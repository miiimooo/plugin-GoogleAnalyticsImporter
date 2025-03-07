<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\GoogleAnalyticsImporter;

use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\DataTable\Renderer\Json;
use Piwik\Date;
use Piwik\Nonce;
use Piwik\Notification;
use Piwik\Piwik;
use Piwik\Plugins\GoogleAnalyticsImporter\Commands\ImportGA4Reports;
use Piwik\Plugins\GoogleAnalyticsImporter\Commands\ImportReports;
use Piwik\Plugins\GoogleAnalyticsImporter\Google\Authorization;
use Piwik\Plugins\GoogleAnalyticsImporter\Google\AuthorizationGA4;
use Piwik\Plugins\GoogleAnalyticsImporter\Input\EndDate;
use Piwik\Plugins\MobileAppMeasurable\Type;
use Piwik\Site;
use Piwik\Url;
use Psr\Log\LoggerInterface;

class Controller extends \Piwik\Plugin\ControllerAdmin
{
    const OAUTH_STATE_NONCE_NAME = 'GoogleAnalyticsImporter.oauthStateNonce';

    public function index($errorMessage = false)
    {
        Piwik::checkUserHasSuperUserAccess();

        $errorMessage = $errorMessage ?: Common::getRequestVar('error', '');
        if (!empty($errorMessage)) {
            if ($errorMessage === 'access_denied') {
                $errorMessage = Piwik::translate('GoogleAnalyticsImporter_OauthFailedMessage');
            }
            $notification = new Notification($errorMessage);
            $notification->context = Notification::CONTEXT_ERROR;
            $notification->type = Notification::TYPE_TRANSIENT;
            Notification\Manager::notify('configureerror', $notification);
        }

        /** @var Authorization $authorization */
        $authorization = StaticContainer::get(Authorization::class);

        $authUrl = null;
        $nonce = null;

        $hasClientConfiguration = $authorization->hasClientConfiguration();
        if ($hasClientConfiguration) {
            try {
                $googleClient = $authorization->getConfiguredClient();
            } catch (\Exception $ex) {
                $authorization->deleteClientConfiguration();

                throw $ex;
            }

            $authUrl = $googleClient->createAuthUrl();

            $nonce = Nonce::getNonce('GoogleAnalyticsImporter.deleteGoogleClientConfig', 1200);
        } else {
            $nonce = Nonce::getNonce('GoogleAnalyticsImporter.googleClientConfig', 1200);
        }

        $importStatus = StaticContainer::get(ImportStatus::class);
        $statuses = $importStatus->getAllImportStatuses($checkKilledStatus = true);
        foreach ($statuses as &$status) {
            if (isset($status['site']) && $status['site'] instanceof Site) {
                $status['site'] = [
                    'idsite' => $status['site']->getId(),
                    'name' => $status['site']->getName(),
                ];
            }
        }

        $stopImportNonce = Nonce::getNonce('GoogleAnalyticsImporter.stopImportNonce', 1200);
        $startImportNonce = Nonce::getNonce('GoogleAnalyticsImporter.startImportNonce', 1200);
        $changeImportEndDateNonce = Nonce::getNonce('GoogleAnalyticsImporter.changeImportEndDateNonce', 1200);
        $resumeImportNonce = Nonce::getNonce('GoogleAnalyticsImporter.resumeImportNonce', 1200);
        $scheduleReImportNonce = Nonce::getNonce('GoogleAnalyticsImporter.scheduleReImport', 1200);

        $maxEndDateDesc = null;

        $endDate = StaticContainer::get(EndDate::class);
        $maxEndDate = $endDate->getConfiguredMaxEndDate();
        if ($maxEndDate == 'today' || $maxEndDate == 'now') {
            $maxEndDateDesc = Piwik::translate('GoogleAnalyticsImporter_TodaysDate');
        } else if ($maxEndDate == 'yesterday' || $maxEndDate == 'yesterdaySameTime') {
            $maxEndDateDesc = Piwik::translate('GoogleAnalyticsImporter_YesterdaysDate');
        } else if (!empty($maxEndDate)) {
            $maxEndDateDesc = Date::factory($maxEndDate)->toString();
        }

        $isClientConfigurable = StaticContainer::get('GoogleAnalyticsImporter.isClientConfigurable');
        return $this->renderTemplate('index', [
            'isClientConfigurable' => $isClientConfigurable,
            'isConfigured' => $authorization->hasAccessToken(),
            'auth_nonce' => Nonce::getNonce('gaimport.auth', 1200),
            'hasClientConfiguration' => $hasClientConfiguration,
            'nonce' => $nonce,
            'statuses' => $statuses,
            'stopImportNonce' => $stopImportNonce,
            'startImportNonce' => $startImportNonce,
            'changeImportEndDateNonce' => $changeImportEndDateNonce,
            'resumeImportNonce' => $resumeImportNonce,
            'scheduleReImportNonce' => $scheduleReImportNonce,
            'maxEndDateDesc' => $maxEndDateDesc,
            'importOptionsUA' => array(
                'ua' => Piwik::translate('GoogleAnalyticsImporter_SelectImporterUATitle')
            ),
            'importOptionsGA4' => [
                'ga4' => Piwik::translate('GoogleAnalyticsImporter_SelectImporterGA4Title')
            ],
            'extraCustomDimensionsField' => [
                'field1' => [
                    'key' => 'gaDimension',
                    'title' => Piwik::translate('GoogleAnalyticsImporter_GADimension'),
                    'uiControl' => 'text',
                    'availableValues' => null,
                ],
                'field2' => [
                    'key' => 'dimensionScope',
                    'title' => Piwik::translate('GoogleAnalyticsImporter_DimensionScope'),
                    'uiControl' => 'select',
                    'availableValues' => [
                        'visit' => Piwik::translate('General_Visit'),
                        'action' => Piwik::translate('General_Action'),
                    ],
                ],
            ],
            'extraCustomDimensionsFieldGA4' => [
                'field1' => [
                    'key' => 'ga4Dimension',
                    'title' => Piwik::translate('GoogleAnalyticsImporter_GA4Dimension'),
                    'uiControl' => 'text',
                    'availableValues' => null,
                ],
                'field2' => [
                    'key' => 'dimensionScope',
                    'title' => Piwik::translate('GoogleAnalyticsImporter_DimensionScope'),
                    'uiControl' => 'select',
                    'availableValues' => [
                        'visit' => Piwik::translate('General_Visit'),
                        'action' => Piwik::translate('General_Action'),
                    ],
                ],
            ],
        ]);
    }

    public function forwardToAuth()
    {
        Piwik::checkUserHasSuperUserAccess();

        Nonce::checkNonce('gaimport.auth', Common::getRequestVar('auth_nonce'));

        /** @var Authorization $authorization */
        $authorization = StaticContainer::get(Authorization::class);

        /** @var \Google\Client $client */
        $client = $authorization->getConfiguredClient();

        $state = Nonce::getNonce(self::OAUTH_STATE_NONCE_NAME, 900);
        $client->setState($state);

        Url::redirectToUrl($client->createAuthUrl());
    }

    public function deleteClientCredentials()
    {
        Piwik::checkUserHasSuperUserAccess();

        Nonce::checkNonce('GoogleAnalyticsImporter.deleteGoogleClientConfig', Common::getRequestVar('config_nonce'));

        /** @var Authorization $authorization */
        $authorization = StaticContainer::get(Authorization::class);

        $authorization->deleteClientConfiguration();

        return $this->index();
    }

    /**
     * Processes the response from google oauth service
     *
     * @return string
     * @throws \Exception
     */
    public function processAuthCode()
    {
        Piwik::checkUserHasSuperUserAccess();

        $error     = Common::getRequestVar('error', '');
        $oauthCode = Common::getRequestVar('code', '');

        Nonce::checkNonce(self::OAUTH_STATE_NONCE_NAME, Common::getRequestVar('state'), 'google.com');

        if ($error) {
            return $this->index($error);
        }

        try {
            /** @var Authorization $authorization */
            $authorization = StaticContainer::get(Authorization::class);

            $client = $authorization->getConfiguredClient();
            $authorization->saveAccessToken($oauthCode, $client);
        } catch (\Exception $e) {
            return $this->index($this->getNotificationExceptionText($e));
        }

        // reload index action to prove everything is configured
        $this->redirectToIndex('GoogleAnalyticsImporter', 'index');
    }

    public function configureClient()
    {
        Piwik::checkUserHasSuperUserAccess();

        Nonce::checkNonce('GoogleAnalyticsImporter.googleClientConfig', Common::getRequestVar('config_nonce'));

        /** @var Authorization $authorization */
        $authorization = StaticContainer::get(Authorization::class);

        $errorMessage = null;

        try {
            $config = Common::getRequestVar('client', '');
            $config = Common::unsanitizeInputValue($config);

            if (empty($config) && !empty($_FILES['clientfile'])) {

                if (!empty($_FILES['clientfile']['error'])) {
                    throw new \Exception('Client file upload failed: ' . $_FILES['clientfile']['error']);
                }

                $file = $_FILES['clientfile']['tmp_name'];
                if (!file_exists($file)) {
                    $logger = StaticContainer::get(LoggerInterface::class);
                    $logger->error('Client file upload failed: temporary file does not exist (path is {path})', [
                        'path' => $file,
                    ]);

                    throw new \Exception('Client file upload failed: temporary file does not exist');
                }

                $config = file_get_contents($_FILES['clientfile']['tmp_name']);
            }

            $authorization->validateConfig($config);
            $authorization->saveConfig($config);
        } catch (\Exception $ex) {
            $errorMessage = $this->getNotificationExceptionText($ex);
            $errorMessage = substr($errorMessage, 0, 1024);
        }

        Url::redirectToUrl(Url::getCurrentUrlWithoutQueryString() . Url::getCurrentQueryStringWithParametersModified([
            'action' => 'index',
            'error' => $errorMessage,
        ]));
    }

    public function deleteImportStatus()
    {
        Piwik::checkUserHasSuperUserAccess();
        $this->checkTokenInUrl();

        Json::sendHeaderJSON();

        try {
            Nonce::checkNonce('GoogleAnalyticsImporter.stopImportNonce', Common::getRequestVar('nonce'));

            $idSite = Common::getRequestVar('idSite', null, 'int');

            /** @var ImportStatus $importStatus */
            $importStatus = StaticContainer::get(ImportStatus::class);
            $importStatus->deleteStatus($idSite);

            echo json_encode(['result' => 'ok']);
        } catch (\Exception $ex) {
            $this->logException($ex, __FUNCTION__);

            $notification = new Notification($this->getNotificationExceptionText($ex));
            $notification->type = Notification::TYPE_TRANSIENT;
            $notification->context = Notification::CONTEXT_ERROR;
            $notification->title = Piwik::translate('General_Error');
            Notification\Manager::notify('GoogleAnalyticsImporter_deleteImportStatus_failure', $notification);
        }
    }

    public function changeImportEndDate()
    {
        Piwik::checkUserHasSuperUserAccess();
        $this->checkTokenInUrl();

        Json::sendHeaderJSON();

        try {
            Nonce::checkNonce('GoogleAnalyticsImporter.changeImportEndDateNonce', Common::getRequestVar('nonce'));

            $idSite = Common::getRequestVar('idSite', null, 'int');
            $endDate = Common::getRequestVar('endDate', '', 'string');

            $inputEndDate = StaticContainer::get(EndDate::class);
            $endDate = $inputEndDate->limitMaxEndDateIfNeeded($endDate);

            /** @var ImportStatus $importStatus */
            $importStatus = StaticContainer::get(ImportStatus::class);
            $status = $importStatus->getImportStatus($idSite);

            $importStatus->setImportDateRange($idSite,
                empty($status['import_range_start']) ? null : Date::factory($status['import_range_start']),
                empty($endDate) ? null : Date::factory($endDate));

            echo json_encode(['result' => 'ok']);
        } catch (\Exception $ex) {
            $this->logException($ex, __FUNCTION__);

            $notification = new Notification($this->getNotificationExceptionText($ex));
            $notification->type = Notification::TYPE_TRANSIENT;
            $notification->context = Notification::CONTEXT_ERROR;
            $notification->title = Piwik::translate('General_Error');
            Notification\Manager::notify('GoogleAnalyticsImporter_changeImportEndDate_failure', $notification);
        }
    }

    public function startImport()
    {
        Piwik::checkUserHasSuperUserAccess();
        $this->checkTokenInUrl();

        Json::sendHeaderJSON();

        try {
            Nonce::checkNonce('GoogleAnalyticsImporter.startImportNonce', Common::getRequestVar('nonce'));

            $startDate = trim(Common::getRequestVar('startDate', ''));
            if (!empty($startDate)) {
                $startDate = Date::factory($startDate . ' 00:00:00');
            }

            $endDate = trim(Common::getRequestVar('endDate', ''));

	        $inputEndDate = StaticContainer::get(EndDate::class);
	        $endDate = $inputEndDate->limitMaxEndDateIfNeeded($endDate);
            if (!empty($endDate)) {
                $endDate = Date::factory($endDate)->getStartOfDay();
            }

            // set credentials in google client
            $googleAuth = StaticContainer::get(Authorization::class);
            $googleAuth->getConfiguredClient();

            /** @var Importer $importer */
            $importer = StaticContainer::get(Importer::class);

            $propertyId = trim(Common::getRequestVar('propertyId'));
            $viewId = trim(Common::getRequestVar('viewId'));
            $accountId = trim(Common::getRequestVar('accountId', false));
            $account = $accountId ?: ImportReports::guessAccountFromProperty($propertyId);
            $isMobileApp = Common::getRequestVar('isMobileApp', 0, 'int') == 1;
            $timezone = trim(Common::getRequestVar('timezone', '', 'string'));
            $extraCustomDimensions = Common::getRequestVar('extraCustomDimensions', [], $type = 'array');
            $isVerboseLoggingEnabled = Common::getRequestVar('isVerboseLoggingEnabled', 0, $type = 'int') == 1;
            $forceCustomDimensionSlotCheck = Common::getRequestVar('forceCustomDimensionSlotCheck', 1, $type = 'int') == 1;

            $idSite = $importer->makeSite($account, $propertyId, $viewId, $timezone, $isMobileApp ? Type::ID : \Piwik\Plugins\WebsiteMeasurable\Type::ID, $extraCustomDimensions,
                $forceCustomDimensionSlotCheck);

            try {
                if (empty($idSite)) {
                    throw new \Exception("Unable to import site entity."); // sanity check
                }

                /** @var ImportStatus $importStatus */
                $importStatus = StaticContainer::get(ImportStatus::class);

                if (!empty($startDate)
                    || !empty($endDate)
                ) {
                    // we set the last imported date to one day before the start date
                    $importStatus->setImportDateRange($idSite, $startDate ?: null, $endDate ?: null);
                }

                if ($isVerboseLoggingEnabled) {
                    $importStatus->setIsVerboseLoggingEnabled($idSite, $isVerboseLoggingEnabled);
                }

                // start import now since the scheduled task may not run until tomorrow
                Tasks::startImport($importStatus->getImportStatus($idSite));
            } catch (\Exception $ex) {
                $importStatus->erroredImport($idSite, $ex->getMessage());

                throw $ex;
            }

            echo json_encode([ 'result' => 'ok' ]);
        } catch (\Exception $ex) {
            $this->logException($ex, __FUNCTION__);

            $notification = new Notification($this->getNotificationExceptionText($ex));
            $notification->type = Notification::TYPE_TRANSIENT;
            $notification->context = Notification::CONTEXT_ERROR;
            $notification->title = Piwik::translate('General_Error');
            Notification\Manager::notify('GoogleAnalyticsImporter_startImport_failure', $notification);
        }
    }

    public function startImportGA4()
    {
        Piwik::checkUserHasSuperUserAccess();
        $this->checkTokenInUrl();

        Json::sendHeaderJSON();

        try {
            Nonce::checkNonce('GoogleAnalyticsImporter.startImportNonce', Common::getRequestVar('nonce'));

            $startDate = trim(Common::getRequestVar('startDate', ''));
            if (!empty($startDate)) {
                $startDate = Date::factory($startDate . ' 00:00:00');
            }

            $endDate = trim(Common::getRequestVar('endDate', ''));

            $inputEndDate = StaticContainer::get(EndDate::class);
            $endDate = $inputEndDate->limitMaxEndDateIfNeeded($endDate);
            if (!empty($endDate)) {
                $endDate = Date::factory($endDate)->getStartOfDay();
            }

            // set credentials in google client
            $googleAuth = StaticContainer::get(AuthorizationGA4::class);

            /** @var ImporterGA4 $importer */
            $importer = StaticContainer::get(ImporterGA4::class);
            $importer->setGAClient($googleAuth->getClient());
            $importer->setGAAdminClient($googleAuth->getAdminClient());

            $propertyId = trim(Common::getRequestVar('propertyId'));
            ImportGA4Reports::validatePropertyID($propertyId);
            $isMobileApp = Common::getRequestVar('isMobileApp', 0, 'int') == 1;
            $timezone = trim(Common::getRequestVar('timezone', '', 'string'));
            $extraCustomDimensions = Common::getRequestVar('extraCustomDimensions', [], $type = 'array');
            $isVerboseLoggingEnabled = Common::getRequestVar('isVerboseLoggingEnabled', 0, $type = 'int') == 1;
            $forceCustomDimensionSlotCheck = Common::getRequestVar('forceCustomDimensionSlotCheck', 1, $type = 'int') == 1;

            $idSite = $importer->makeSite($propertyId, $timezone, $isMobileApp ? Type::ID : \Piwik\Plugins\WebsiteMeasurable\Type::ID, $extraCustomDimensions,
                $forceCustomDimensionSlotCheck);

            try {
                if (empty($idSite)) {
                    throw new \Exception("Unable to import site entity."); // sanity check
                }

                /** @var ImportStatus $importStatus */
                $importStatus = StaticContainer::get(ImportStatus::class);

                if (!empty($startDate)
                    || !empty($endDate)
                ) {
                    // we set the last imported date to one day before the start date
                    $importStatus->setImportDateRange($idSite, $startDate ?: null, $endDate ?: null);
                }

                if ($isVerboseLoggingEnabled) {
                    $importStatus->setIsVerboseLoggingEnabled($idSite, $isVerboseLoggingEnabled);
                }

                // start import now since the scheduled task may not run until tomorrow
                Tasks::startImportGA4($importStatus->getImportStatus($idSite));
            } catch (\Exception $ex) {
                $importStatus->erroredImport($idSite, $ex->getMessage());

                throw $ex;
            }

            echo json_encode([ 'result' => 'ok' ]);
        } catch (\Exception $ex) {
            $this->logException($ex, __FUNCTION__);

            $notification = new Notification($this->getNotificationExceptionText($ex));
            $notification->type = Notification::TYPE_TRANSIENT;
            $notification->context = Notification::CONTEXT_ERROR;
            $notification->title = Piwik::translate('General_Error');
            Notification\Manager::notify('GoogleAnalyticsImporter_startImport_failure', $notification);
        }
    }

    public function resumeImport()
    {
        Piwik::checkUserHasSuperUserAccess();
        $this->checkTokenInUrl();

        Json::sendHeaderJSON();

        try {
            Nonce::checkNonce('GoogleAnalyticsImporter.resumeImportNonce', Common::getRequestVar('nonce'));

            $idSite = Common::getRequestVar('idSite', null, 'int');
            $isGA4 = Common::getRequestVar('isGA4', 0, 'int') == 1;
            new Site($idSite);

            /** @var ImportStatus $importStatus */
            $importStatus = StaticContainer::get(ImportStatus::class);
            $status = $importStatus->getImportStatus($idSite);
            if ($status['status'] == ImportStatus::STATUS_FINISHED) {
                throw new \Exception("This import cannot be resumed since it is finished.");
            }

            $importStatus->resumeImport($idSite);

            if ($isGA4) {
                Tasks::startImportGA4($status);
            } else {
                Tasks::startImport($status);
            }

            echo json_encode([ 'result' => 'ok' ]);
        } catch (\Exception $ex) {
            $this->logException($ex, __FUNCTION__);

            $notification = new Notification($this->getNotificationExceptionText($ex));
            $notification->type = Notification::TYPE_TRANSIENT;
            $notification->context = Notification::CONTEXT_ERROR;
            $notification->title = Piwik::translate('General_Error');
            Notification\Manager::notify('GoogleAnalyticsImporter_resumeImport_failure', $notification);
        }
    }

    public function scheduleReImport()
    {
        Piwik::checkUserHasSuperUserAccess();
        $this->checkTokenInUrl();

        Json::sendHeaderJSON();

        try {
            Nonce::checkNonce('GoogleAnalyticsImporter.scheduleReImport', Common::getRequestVar('nonce'));

            $idSite = Common::getRequestVar('idSite', null, 'int');
            new Site($idSite);

            $isGA4 = Common::getRequestVar('isGA4', 0, 'int') == 1;
            $startDate = Common::getRequestVar('startDate', null, 'string');
            $startDate = Date::factory($startDate);

            $endDate = Common::getRequestVar('endDate', null, 'string');

	        $inputEndDate = StaticContainer::get(EndDate::class);
	        $endDate = $inputEndDate->limitMaxEndDateIfNeeded($endDate);

	        $endDate = Date::factory($endDate);

            /** @var ImportStatus $importStatus */
            $importStatus = StaticContainer::get(ImportStatus::class);
            $importStatus->reImportDateRange($idSite, $startDate, $endDate);
            $importStatus->resumeImport($idSite);

            // start import now since the scheduled task may not run until tomorrow
            if ($isGA4) {
                Tasks::startImportGA4($importStatus->getImportStatus($idSite));
            } else {
                Tasks::startImport($importStatus->getImportStatus($idSite));
            }

            echo json_encode([ 'result' => 'ok' ]);
        } catch (\Exception $ex) {
            $this->logException($ex, __FUNCTION__);

            $notification = new Notification($this->getNotificationExceptionText($ex));
            $notification->type = Notification::TYPE_TRANSIENT;
            $notification->context = Notification::CONTEXT_ERROR;
            $notification->title = Piwik::translate('General_Error');
            Notification\Manager::notify('GoogleAnalyticsImporter_rescheduleImport_failure', $notification);
        }
    }

    private function logException(\Throwable $ex, $functionName)
    {
        StaticContainer::get(LoggerInterface::class)->debug('Encountered exception in GoogleAnalyticsImporter.{function} controller method: {exception}', [
            'exception' => $ex,
            'function' => $functionName,
        ]);
    }

    private function getNotificationExceptionText(\Exception $e)
    {
        $message = $e->getMessage();
        $messageContent = @json_decode($message, true);
        if (\Piwik_ShouldPrintBackTraceWithMessage()) {
            $message .= "\n" . $e->getTraceAsString();
        } else if (isset($messageContent['error']['message'])) {
            $message = $messageContent['error']['message'];
        }
        return $message;
    }

    public function pendingImports()
    {
        $pendingImports = GoogleAnalyticsImporter::canDisplayImportPendingNotice();
        return json_encode($pendingImports);
    }
}
