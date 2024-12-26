<?php

namespace Diversworld\CalendarEditorBundle\Controller\Module;

use Contao\BackendTemplate;
use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\ContentModel;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\Email;
use Contao\FormCaptcha;
use Contao\FormCheckbox;
use Contao\FormRadio;
use Contao\FormSelect;
use Contao\FormText;
use Contao\FormTextarea;
use Contao\FrontendUser;
use Contao\Image\Exception\InvalidArgumentException;
use Contao\Image\Exception\RuntimeException;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Diversworld\CalendarEditorBundle\Models\CalendarEventsModelEdit;
use Diversworld\CalendarEditorBundle\Models\CalendarModelEdit;
use Diversworld\CalendarEditorBundle\Services\CheckAuthService;
use Contao\Date;
use Contao\Events;
use Contao\FrontendTemplate;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ModuleEventEditor extends Events
{
    /**
     * Template
     *
     * @var string
     */
    protected $strTemplate = 'eventEdit_default';
    protected string $errorString = '';
    protected array $allowedCalendars = [];

    private ScopeMatcher $scopeMatcher; // Dependency Injection für ScopeMatcher
    private RequestStack $requestStack; // Dependency Injection für RequestStack
    private TokenChecker $tokenChecker;
    private ?CheckAuthService $checkAuthService = null;
    private Connection $connection;

    private LoggerInterface $logger;
    protected function initializeLogger(): void
    {
        $this->logger = System::getContainer()->get('monolog.logger.contao.general');
    }

    protected function initializeServices(): void
    {
        $container = System::getContainer();

        $this->checkAuthService = $container->get('Diversworld\CalendarEditorBundle\Services\CheckAuthService');
        $this->scopeMatcher = $container->get('contao.routing.scope_matcher');
        $this->requestStack = $container->get('request_stack');
        // Hole die Doctrine Connection
        $this->tokenChecker = $container->get('contao.security.token_checker');
        $this->connection = $container->get('doctrine')->getConnection();
    }
    /**
     * generate Module
     */
    public function generate() : string
    {
        $this->initializeServices();
        $request = $this->requestStack->getCurrentRequest();

		//if ($request && System::getContainer()->get('contao.routing.scope_matcher')->isBackendRequest($request))
        if( $this->scopeMatcher->isBackendRequest($request))
        {
            $objTemplate = new BackendTemplate('be_wildcard');
            $objTemplate->wildcard = '### EVENT EDITOR ###';
            $objTemplate->title = $this->headline;
            $objTemplate->id = $this->id;
            $objTemplate->link = $this->name;
            $objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;
            return $objTemplate->parse();
        }

        $this->cal_calendar = $this->sortOutProtected(StringUtil::deserialize($this->cal_calendar));

        // Return if there are no calendars
        if (!is_array($this->cal_calendar) || count($this->cal_calendar) < 1) {
            return '';
        }

        return parent::generate();
    }
    /**
     * Returns an Event-URL for a given Event-Editor and a given Event
     **/
    public function getEditorFrontendURLForEvent($event): ?string
    {
        $this->initializeServices();
        // Prüfen, ob das Event ein gültiges Objekt ist
        if (!$event || !$event->pid) {
            return null;
        }

        // Zielseite des Events ermitteln
        $pageModel = PageModel::findByPk($event->pid);

        if (!$pageModel) {
            return null;
        }

        // UrlGenerator-Dienst laden
        $urlGenerator = $this->container->get(UrlGenerator::class);

        return $urlGenerator->generateFrontendUrl($pageModel->row(), [
            'alias' => $event->alias
        ]);
    }

    public function addTinyMCE($configuration): void
    {
        if (!empty($configuration)) {
            $this->rteFields = 'ctrl_details,ctrl_teaser,teaser';
            // Fallback to English if the user language is not supported
            $this->language = 'en';

            $rootDir = System::getContainer()->getParameter('kernel.project_dir');

            $file = sprintf('%s/vendor/mindbird/contao-calendar-editor/src/Resources/contao/tinyMCE/%s.php', $rootDir, $configuration);
            if (file_exists($rootDir . '/assets/tinymce4/js/langs/' . $GLOBALS['TL_LANGUAGE'] . '.js')) {
                $this->language = $GLOBALS['TL_LANGUAGE'];
            }

            if (!file_exists($file)) {
                echo(sprintf('Cannot find rich text editor configuration file "%s"', $file));
            } else {
                ob_start();
                include($file);
                $GLOBALS['TL_HEAD'][] = ob_get_contents();
                ob_end_clean();
            }
        }
    }
    /**
     * Get the calendars the user is allowed to edit
     * These calendars will appear in the selection-field in the edit-form (if there is not only one)
     */
    public function getCalendars($user): array
    {
        $this->initializeServices();
        // get all the calendars supported by this module
        $calendarModels = CalendarModelEdit::findByIds($this->cal_calendar);
        // Check these calendars, whether the current user is allowed to edit them
        $calendars = [];

        if (null === $calendarModels) {
            // return the empty array
            return $calendars;
        } else {
            // fill the Allowed-Calendars-Array with proper calendars
            foreach ($calendarModels as $calendarModel) {
                if ($this->checkAuthService->isUserAuthorized($calendarModel, $user)) {
                    $calendars[] = $calendarModel;
                }
            }
        }
        return $calendars;
    }
    /**
     * Check user rights for editing on different stages of the formular
     * The first step is always to get an Calendar-object frome the array of calendars by the
     * current events Pid (= the ID of the calendar)
     **/
    public function getCalendarObjectFromPID($pid) : ?CalendarModel
    {
        foreach ($this->allowedCalendars as $objCalendar) {
            if ($pid == $objCalendar->id) {
                return $objCalendar;
            }
        }
        return NULL;
    }

    public function UserIsToAddCalendar($user, $pid) : bool
    {
        $objCalendar = $this->getCalendarObjectFromPID($pid);

        if (NULL === $objCalendar) {
            return false;
        } else {
            return $this->checkAuthService->isUserAuthorized($objCalendar, $user);
        }
    }

    public function checkValidDate($calendarID, $objStart, $objEnd) : bool
    {
        $objCalendar = $this->getCalendarObjectFromPID($calendarID);
        if (NULL === $objCalendar) {
            return false;
        }
        $tmpStartDate = strtotime($objStart->__get('value'));
        $tmpEndDate = strtotime($objEnd->__get('value'));
        if ($tmpEndDate === false) $tmpEndDate = null;

        if ((!$objCalendar->caledit_onlyFuture) || $this->checkAuthService->isUserAdmin($objCalendar, $this->User)) {
            // elapsed events can be edited, or user is an admin
            return true;
        } else {
            // editing elapsed events is denied and user is not an admin
            //$isValid = ($newDate >= time());
            $isValid = $this->checkAuthService->isDateNotElapsed($tmpStartDate, $tmpEndDate);
            if (!$isValid) {
                if (!$tmpEndDate && ($this->checkAuthService->getMidnightTime() > $tmpStartDate)) {
                    $objStart->addError($GLOBALS['TL_LANG']['MSC']['caledit_formErrorElapsedDate']);
                }
                if ($tmpEndDate && ($this->checkAuthService->getMidnightTime() > $tmpEndDate)) {
                    $objEnd->addError($GLOBALS['TL_LANG']['MSC']['caledit_formErrorElapsedDate']);
                }
            }
            return $isValid;
        }
    }

    public function allDatesAllowed($calendarID) : bool
    {
        $objCalendar = $this->getCalendarObjectFromPID($calendarID);
        if (NULL === $objCalendar) {
            return false;
        }

        if ((!$objCalendar->caledit_onlyFuture) || ($this->checkAuthService->isUserAdmin($objCalendar, $this->User))) {
            // elapsed events can be edited, or user is an admin
            return true;
        } else {
            return false;
        }
    }
    /**
     * check, whether the user is allowed to edit the specified Event
     * This is called when the user has general access to at least one calendar
     * But: We need to check whether he is allowed to edit this special event
     *       - is he in the group/admingroup in the event's calendar?
     *       - is he the owner of the event or !caledit_onlyUser
     * used in the compile-method at the beginning
     */
    public function checkUserEditRights($user, $eventID, $currentObjectData): bool
    {
        $this->initializeServices();

        // if no event is specified: ok, FE user can add new events :D
        if (!$eventID) {
            return true;
        }

        $objCalendar = $this->getCalendarObjectFromPID($currentObjectData->pid);

        if (NULL === $objCalendar) {
            $this->errorString = $GLOBALS['TL_LANG']['MSC']['caledit_unexpected'] . $currentObjectData->pid;
            return false; // Event not found or something else is wrong
        }

        if (!$objCalendar->AllowEdit) {
            $this->errorString = $GLOBALS['TL_LANG']['MSC']['caledit_NoEditAllowed'] . '(checkUserEditRights)';
            return false;
        }

        // check calendar settings
        if ($this->checkAuthService->isUserAuthorized($objCalendar, $user)) {
            // if the editing is disabled in the BE: Deny editing in the FE
            if ($currentObjectData->disable_editing) {
                $this->errorString = $GLOBALS['TL_LANG']['MSC']['caledit_DisabledEvent'];
                return false;
            }

            $userIsAdmin = $this->checkAuthService->isUserAdmin($objCalendar, $user);
            //if (!$userIsAdmin && ($CurrentObjectData->startTime <= time()) && ($objCalendar->caledit_onlyFuture)){
            if (!$userIsAdmin
                && (!$this->checkAuthService->isDateNotElapsed($currentObjectData->startTime, $currentObjectData->endTime))
                //($CurrentObjectData->startTime <= time())
                && ($objCalendar->caledit_onlyFuture)) {
                $this->errorString = $GLOBALS['TL_LANG']['MSC']['caledit_NoPast'];
                return false;
            }
            $hasFrontendUser =  $this->tokenChecker->hasFrontendUser();

            $result = ((!$objCalendar->caledit_onlyUser) || (($hasFrontendUser) && ($userIsAdmin || ($user->id == $currentObjectData->fe_user))));
            if (!$result) {
                $this->errorString = $GLOBALS['TL_LANG']['MSC']['caledit_OnlyUser'];
            }

            return $result;
        } else {
            $this->errorString = $GLOBALS['TL_LANG']['MSC']['caledit_UnauthorizedUser'];
            return false; // user is not allowed to edit events here
        }
    }

    public function generateRedirect(string $userSetting, ?int $DBid): void
    {
        $this->initializeServices();
        $currentRequest = $this->requestStack->getCurrentRequest();
        $router = System::getContainer()->get('router'); // Symfony Router

        // Abrufen der aktuellen URL ohne Query-Parameter
        $currentUrl = $currentRequest->getUri();
        $jumpTo = preg_replace('/\?.*$/i', '', $currentUrl);

        switch ($userSetting) {
            case "":
                if ($this->jumpTo) {
                    $pageModel = PageModel::findByPk($DBid);
                    if ($pageModel !== null) {
                        $urlGenerator = System::getContainer()->get('contao.routing.content_url_generator');
                        $jumpTo = $urlGenerator->generate($pageModel);
                    }
                }
                break;

            case "new":
                $jumpTo .= '?new=true';
                break;

            case "view":
                $currentEventObject = CalendarEventsModel::findByIdOrAlias($DBid);
                if ($currentEventObject !== null) {
                    if ($currentEventObject->published) {
                        // URL-Generator verwenden
                        $urlGenerator = System::getContainer()->get('contao.routing.content_url_generator');
                        $jumpTo = $urlGenerator->generate($currentEventObject);
                    } else {
                        $jumpTo = '?edit=' . $DBid; // Event nicht veröffentlicht, Bearbeitungslink
                    }
                } else {
                    throw new \RuntimeException("Event with ID $DBid not found.");
                }
                break;

            case "edit":
                $jumpTo .= '?edit=' . $DBid;
                break;

            case "clone":
                $jumpTo .= '?clone=' . $DBid;
                break;

            default:
                throw new InvalidArgumentException("Unknown userSetting: $userSetting");
        }

        // Redirect ausführen
        $this->redirect($jumpTo, 301);
    }

    public function getContentElements($eventID, &$contentID, &$contentData): void
    {
        // get Content Elements
        $objElement = ContentModel::findPublishedByPidAndTable($eventID, 'tl_calendar_events');

        // analyse content elements:
        // we will use the first element of type "text", discard the others (but set a warning in the template)
        $this->Template->ContentWarning = '';
        $this->Template->ImageWarning = '';
        if ($objElement !== null) {
            $ContentCount = 0;
            $TextFound = false;
            while ($objElement->next()) {
                $ContentCount++;
                if (($objElement->type == 'text') and (!$TextFound)) {
                    $contentData['text'] = $objElement->text;
                    $contentID = $objElement->id;
                    $TextFound = true;
                    if ($objElement->addImage) {
                        // we cannot modify "add image" with this module.
                        // note: A "headline" will be deleted without warning.
                        $this->Template->ImageWarning = $GLOBALS['TL_LANG']['MSC']['caledit_ContentElementWithImage'];
                    }
                }
            }
            if ($ContentCount > 1) {
                $this->Template->ContentWarning = $GLOBALS['TL_LANG']['MSC']['caledit_MultipleContentElements'];
            }
        }
    }

    public function getEventInformation($currentEventObject, &$newEventData): void
    {
        // Fill fields with data from $currentEventObject
        $newEventData['startDate'] = $currentEventObject->startDate;
        $newEventData['endDate'] = $currentEventObject->endDate;
        if ($currentEventObject->addTime) {
            $newEventData['startTime'] = $currentEventObject->startTime;
            $newEventData['endTime'] = $currentEventObject->endTime;
            if ($newEventData['startTime'] == $newEventData['endTime']) {
                $newEventData['endTime'] = '';
            }
        } else {
            $newEventData['startTime'] = '';
            $newEventData['endTime'] = '';
        }
        $newEventData['title'] = $currentEventObject->title;
        $newEventData['teaser'] = $currentEventObject->teaser;
        $newEventData['location'] = $currentEventObject->location;
        $newEventData['cssClass'] = $currentEventObject->cssClass;
        $newEventData['pid'] = $currentEventObject->pid;
        $newEventData['published'] = $currentEventObject->published;
        $newEventData['alias'] = $currentEventObject->alias;

        $this->Template->CurrentTitle = $currentEventObject->title;
        $this->Template->CurrentDate = $this->Date::parseDate($GLOBALS['TL_CONFIG']['dateFormat'], $currentEventObject->startDate);
        $this->Template->CurrentPublished = $currentEventObject->published;

        $urlGenerator = $this->container->get(UrlGenerator::class);
        if ($currentEventObject->published) {
            $this->Template->CurrentEventLink = $urlGenerator->generateEventUrl($currentEventObject);
            $this->Template->CurrentPublishedInfo = $GLOBALS['TL_LANG']['MSC']['caledit_publishedEvent'];
        } else {
            $this->Template->CurrentEventLink = '';
            $this->Template->CurrentPublishedInfo = $GLOBALS['TL_LANG']['MSC']['caledit_unpublishedEvent'];
        }
    }

    public function addDatePicker(&$field): void
    {
        $field['inputType'] = 'calendarfield';
        if (strlen($this->caledit_dateIncludeCSSTheme) > 0) {
            $field['eval']['dateIncludeCSS'] = '1';
            $field['eval']['dateIncludeCSSTheme'] = $this->caledit_dateIncludeCSSTheme;
        } else {
            $field['eval']['dateIncludeCSS'] = '0';
            $field['eval']['dateIncludeCSSTheme'] = '';
        }
        $field['eval']['dateDirection'] = $this->caledit_dateDirection;
        if ($this->caledit_dateImage) {
            $field['eval']['dateImage'] = '1';
        }
        if ($this->caledit_dateImageSRC) {
            $field['eval']['dateImageSRC'] = $this->caledit_dateImageSRC;
        }
    }

    public function aliasExists(string $suggestedAlias): bool
    {
        $this->initializeServices();
        $query = $this->connection->createQueryBuilder()
            ->select('id')
            ->from('tl_calendar_events')
            ->where('alias = :alias')
            ->setParameter('alias', $suggestedAlias);

        $result = $query->executeQuery();

        return $result->rowCount() > 0;
    }

    public function generateAlias($value): string
    {
        // Maximum length of alias in the DB: 128 chars
        // We use only 110 chars here, as we may add "-<ID>" in case of a collision
        $value = substr(StringUtil::standardize($value), 0, 110);

        if ($this->aliasExists($value)) {
            // Alias already exists, we have to modify it.
            // 1st try: Add the ID of the event (which is currently not in the DB, therefore +1 at the end)
            $query = $this->connection->createQueryBuilder()
                ->select('MAX(id) AS id')
                ->from('tl_calendar_events');

            $result = $query->executeQuery()->fetchAssociative();

            $newID = ($result['id'] ?? 0) + 1;
            $value .= '-' . $newID;

            // If even this modified alias exists: use random alias, with ID as prefix
            while ($this->aliasExists($value)) {
                $randID = mt_rand();
                $value = $newID . '-' . $randID;
            }
        }

        return $value;
    }

    public function saveToDB($eventData, $oldId, array $contentData, $oldContentId) : int
    {
        $this->initializeServices();
        if ($oldId === '') {
            // create new alias
            $eventData['alias'] = $this->generateAlias($eventData['title']);
        }

        // important (otherwise details/teaser will be mixed up in calendars or event lists)
        $eventData['source'] = 'default';

        // needed later!
        $startDate = new Date($eventData['startDate'], $GLOBALS['TL_CONFIG']['dateFormat']);

        $eventData['tstamp'] = $startDate->tstamp;

        // Dealing with empty enddates, Start/endtimes ...
        if (trim($eventData['endDate']) != '') {
            // an enddate is given
            $endDateStr = $eventData['endDate'];
            $endDate = new Date($eventData['endDate'], $GLOBALS['TL_CONFIG']['dateFormat']);
            $eventData['endDate'] = $endDate->tstamp;
        } else {
            // needed later
            $endDateStr = $eventData['startDate'];
            // $endDate = $startDate;
            // no enddate is given. => Set it to NULL
            $eventData['endDate'] = NULL;
        }

        $startTimeStr = $eventData['startTime'];
        if (trim($eventData['startTime']) == '') {
            // Dont add time
            $useTime = false;
            $eventData['addTime'] = '';
            $eventData['startTime'] = $startDate->tstamp;
        } else {
            // Add time to the event
            $useTime = true;
            $eventData['addTime'] = '1';
            $startTime = new Date($eventData['startDate'] . ' ' . $eventData['startTime'], $GLOBALS['TL_CONFIG']['dateFormat'] . ' ' . $GLOBALS['TL_CONFIG']['timeFormat']);
            $eventData['startTime'] = $startTime->tstamp;
        }

        $eventData['startDate'] = $startDate->tstamp;

        if (trim($eventData['endTime']) == '') {
            // if no endtime is given: set endtime = starttime
            $dateString = $endDateStr . ' ' . $startTimeStr;
        } else {
            if (!$useTime) {
                $eventData['endTime'] = strtotime($endDateStr . ' ' . $eventData['endTime']);
            }
            $dateString = $endDateStr . ' ' . $eventData['endTime'];
        }
        $endTime = new Date($dateString, $GLOBALS['TL_CONFIG']['datimFormat']);
        $eventData['endTime'] = $endTime->tstamp;


        // Hier: Hooks mit $eventData aufrufen
        if (array_key_exists('prepareCalendarEditData', $GLOBALS['TL_HOOKS']) && is_array($GLOBALS['TL_HOOKS']['prepareCalendarEditData'])) {
            foreach ($GLOBALS['TL_HOOKS']['prepareCalendarEditData'] as $callback) {
                $this->import($callback[0]);
                $eventData = $this->{$callback[0]}->{$callback[1]}($eventData);
            }
        }

        if (empty($oldId)) {
            // Neuer Eintrag
            $this->connection->insert('tl_calendar_events', $eventData);
            $newCid = (int) $this->connection->lastInsertId();
            $contentData['pid'] = $newCid;
            $returnID = $newCid;
        } else {
            // Vorhandenen Eintrag aktualisieren
            $this->connection->update('tl_calendar_events', $eventData, ['id' => $oldId]);
            $contentData['pid'] = $oldId;
            $returnID = $oldId;
        }

        $contentData['ptable'] = 'tl_calendar_events';
        $contentData['type'] = 'text';
        // Setze die Überschrift im Content-Element auf ""
        $contentData['headline'] = serialize(['unit' => 'h1', 'value' => '']);

        if (isset($contentData['text'])) {
            // 'text' ist gesetzt, daher in die Datenbank schreiben
            if (empty($oldContentId)) {
                // Neuer Eintrag
                $contentData['tstamp'] = time();
                $this->connection->insert('tl_content', $contentData);
            } else {
                // Vorhandenen Eintrag aktualisieren
                $this->connection->update('tl_content', $contentData, ['id' => $oldContentId]);
            }
        } else {
            // 'text' ist leer, vorhandenes Content-Element löschen
            if (!empty($oldContentId)) {
                $this->connection->delete('tl_content', ['id' => $oldContentId]);
            }
        }

        // Kalender-Feed aktualisieren
        //$this->calendar->generateFeed($eventData['pid']);

        return $returnID;
    }

    protected function handleEdit($editID, $currentEventObject): void
    {
        $this->initializeServices();
        $this->initializeLogger();

        $this->strTemplate = $this->caledit_template;

        $this->Template = new FrontendTemplate($this->strTemplate);

        // Input über den Symfony-DI-Container beziehen
        $currentRequest = $this->requestStack->getCurrentRequest();

        // 1. Get Data from post/get
        $newDate = $currentRequest->query->get('add');

        $newEventData = [];
        $NewContentData = [];
        $newEventData['startDate'] = $newDate;

        $published = $currentEventObject?->published;

        // Abrufen der aktuellen URL
        $currentUrl = $currentRequest->getUri();

        if ($editID) {
            // get a proper Content-Element
            $this->getContentElements($editID, $contentID, $NewContentData);
            // get the rest of the event data
            $this->getEventInformation($currentEventObject, $newEventData);

            if ($this->caledit_allowDelete) {
                // add a "Delete this event"-Link
                $del = str_replace('?edit=', '?delete=', $currentUrl);
                $this->Template->deleteRef = $del;
                $this->Template->deleteLabel = $GLOBALS['TL_LANG']['MSC']['caledit_deleteLabel'];
                $this->Template->deleteTitle = $GLOBALS['TL_LANG']['MSC']['caledit_deleteTitle'];
            }

            if ($this->caledit_allowClone) {
                $cln = str_replace('?edit=', '?clone=', $currentUrl);
                $this->Template->cloneRef = $cln;
                $this->Template->cloneLabel = $GLOBALS['TL_LANG']['MSC']['caledit_cloneLabel'];
                $this->Template->cloneTitle = $GLOBALS['TL_LANG']['MSC']['caledit_cloneTitle'];
            }

            $this->Template->CurrentPublished = $published;

            if ($published && !$this->caledit_allowPublish) {
                // editing a published event with no publish-rights
                // will hide the event again
                $published = '';
            }
        } else {
            $this->Template->CurrentPublishedInfo = $GLOBALS['TL_LANG']['MSC']['caledit_newEvent'];
        }

        $saveAs = '0';
        $jumpToSelection = '';

        // after this: Overwrite it with the post data
        if($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit') {
            $newEventData['startDate']  = $currentRequest->request->get('startDate');
            $newEventData['endDate']    = $currentRequest->request->get('endDate');
            $newEventData['startTime']  = $currentRequest->request->get('startTime');
            $newEventData['endTime']    = $currentRequest->request->get('endTime');
            $newEventData['title']      = $currentRequest->request->get('title');
            $newEventData['location']   = $currentRequest->request->get('location');
            $newEventData['teaser']     = $currentRequest->request->get('teaser', true);
            $NewContentData['text']     = $currentRequest->request->get('details', true);
            $newEventData['cssClass']   = $currentRequest->request->get('cssClass');
            $newEventData['pid']        = $currentRequest->request->get('pid');
            $newEventData['published']  = $currentRequest->request->get('published');
            $saveAs                     = $currentRequest->request->get('saveAs') ?? 0;
            $jumpToSelection            = $currentRequest->request->get('jumpToSelection');

            if ($published && !$this->caledit_allowPublish) {
                // this should never happen, except the FE user is manipulating
                // the POST-Data with some evil HackerToolz ;-)
                $fatalError = $GLOBALS['TL_LANG']['MSC']['caledit_NoPublishAllowed'] . ' (POST data invalid)';
                $this->Template->FatalError = $fatalError;
                return;
            }

            if (empty($newEventData['pid'])) {
                // set default value
                $newEventData['pid'] = $this->allowedCalendars[0]->id; //['id'];
            }

            if (!$this->UserIsToAddCalendar($this->User, $newEventData['pid'])) {
                // this should never happen, except the FE user is manipulating
                // the POST with some evil HackerToolz. ;-)
                $fatalError = $GLOBALS['TL_LANG']['MSC']['caledit_NoEditAllowed'] . ' (POST data invalid)';
                $this->Template->FatalError = $fatalError;
                return;
            }
        }

        $mandfields = unserialize($this->caledit_mandatoryfields);
        $mandTeaser = (is_array($mandfields) && array_intersect(array('teaser'), $mandfields));
        $mandLocation = (is_array($mandfields) && array_intersect(array('location'), $mandfields));
        $mandDetails = (is_array($mandfields) && array_intersect(array('details'), $mandfields));
        $mandStarttime = (is_array($mandfields) && array_intersect(array('starttime'), $mandfields));
        $mandCss = (is_array($mandfields) && array_intersect(array('css'), $mandfields));

        // fill template with fields ...
        $fields = [];
        $fields['startDate'] = [
            'name' => 'startDate',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_startdate'],
            'inputType' => 'text', // or: 'calendarfield' (see below),
            'value' => $newEventData['startDate'],
            'eval' => [
                'rgxp' => 'date',
                'mandatory' => true,
                'decodeEntities' => true
            ]
        ];

        $fields['endDate'] = [
            'name' => 'endDate',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_enddate'],
            'inputType' => 'text',
            'value' => $newEventData['endDate'] ?? null,
            'eval' => [
                'rgxp' => 'date',
                'mandatory' => false,
                'maxlength' => 128,
                'decodeEntities' => true
            ]
        ];

        /*if ($this->caledit_useDatePicker) {
            $this->addDatePicker($fields['startDate']);
            $this->addDatePicker($fields['endDate']);
        }*/

        $fields['startTime'] = [
            'name' => 'startTime',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_starttime'],
            'inputType' => 'text',
            'value' => $newEventData['startTime'] ?? '',
            'eval' => [
                'rgxp' => 'time',
                'mandatory' => $mandStarttime,
                'maxlength' => 128,
                'decodeEntities' => true
            ]
        ];

        $fields['endTime'] = [
            'name' => 'endTime',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_endtime'],
            'inputType' => 'text',
            'value' => $newEventData['endTime'] ?? '',
            'eval' => [
                'rgxp' => 'time',
                'mandatory' => false,
                'maxlength' => 128,
                'decodeEntities' => true
            ]
        ];

        $fields['title'] = [
            'name' => 'title',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_title'],
            'inputType' => 'text',
            'value' => $newEventData['title'] ?? '',
            'eval' => [
                'mandatory' => true,
                'maxlength' => 255,
                'decodeEntities' => true
            ]
        ];

        $fields['location'] = [
            'name' => 'location',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_location'],
            'inputType' => 'text',
            'value' => $newEventData['location'] ?? '',
            'eval' => [
                'mandatory' => $mandLocation,
                'maxlength' => 255,
                'decodeEntities' => true
            ]
        ];

        $fields['teaser'] = [
            'name' => 'teaser',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_teaser'],
            'inputType' => 'textarea',
            'value' => $newEventData['teaser'] ?? '',
            'eval' => [
                'mandatory' => $mandTeaser,
                'rte' => 'tinyMCE',
                'allowHtml' => true
            ]
        ];

        $fields['details'] = [
            'name' => 'details',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_details'],
            'inputType' => 'textarea',
            'value' => $NewContentData['text'] ?? '',
            'eval' => [
                'mandatory' => $mandDetails,
                'rte' => 'tinyMCE',
                'allowHtml' => true
            ]
        ];

        if (count($this->allowedCalendars) > 1) {
            // Show allowed Calendars in a select-field
            $pref = [];
            $popt = [];
            foreach ($this->allowedCalendars as $cal) {
                $popt[] = $cal->id;
                $pref[$cal->id] = $cal->title;
            }
            $fields['pid'] = [
                'name' => 'pid',
                'label' => $GLOBALS['TL_LANG']['MSC']['caledit_pid'],
                'inputType' => 'select',
                'options' => $popt,
                'value' => $newEventData['pid'] ?? $cal->id,
                'reference' => $pref,
                'eval' => [
                    'mandatory' => true
                ]
            ];
        }

        $xx = $this->caledit_alternateCSSLabel;
        $cssLabel = (empty($xx)) ? $GLOBALS['TL_LANG']['MSC']['caledit_css'] : $this->caledit_alternateCSSLabel;

        if ($this->caledit_usePredefinedCss) {
            $cssValues = StringUtil::deserialize($this->caledit_cssValues);

            $ref = [];
            $opt = [];


            foreach ($cssValues as $cssv) {
                $opt[] = $cssv['value'];
                $ref[$cssv['value']] = $cssv['label'];
            }

            $fields['cssClass'] = [
                'name' => 'cssClass',
                'label' => $cssLabel,
                'inputType' => 'select',
                'options' => $opt,
                'value' => $newEventData['cssClass'] ?? '',
                'reference' => $ref,
                'eval' => [
                    'mandatory' => $mandCss,
                    'includeBlankOption' => true,
                    'maxlength' => 128, 'decodeEntities' => true
                ]
            ];
        } else {
            $fields['cssClass'] = [
                'name' => 'cssClass',
                'label' => $cssLabel,
                'inputType' => 'text',
                'value' => $newEventData['cssClass'] ?? '',
                'eval' => [
                    'mandatory' => $mandCss,
                    'maxlength' => 128,
                    'decodeEntities' => true
                ]
            ];
        }

        if ($this->caledit_allowPublish) {
            $fields['published'] = [
                'name' => 'published',
                'label' => $GLOBALS['TL_LANG']['MSC']['caledit_published'], // Falls ein Label benötigt wird
                'inputType' => 'checkbox',
                'value' => $newEventData['published'] ?? '',
                'options' => [
                    [
                        'value' => 1,
                        'label' => $GLOBALS['TL_LANG']['MSC']['caledit_published']
                    ],
                ],
                'eval' => [
                    'mandatory' => false,
                ],
            ];
        }

        if ($editID) {
            // create a checkbox "save as copy"
            $fields['saveAs'] = [
                'name' => 'saveAs',
                'label' => '', // $GLOBALS['TL_LANG']['MSC']['caledit_saveAs']
                'inputType' => 'checkbox',
                'value' => $saveAs
            ];
            $fields['saveAs']['options']['1'] = $GLOBALS['TL_LANG']['MSC']['caledit_saveAs'];
        }

        $hasFrontendUser =  $this->tokenChecker->hasFrontendUser();

        if (!$hasFrontendUser) {
            $fields['captcha'] = [
                'name' => 'captcha',
                'inputType' => 'captcha',
                'eval' => ['mandatory' => true, 'customTpl' => 'form_captcha_calendar-editor']
            ];
        }

        // Create jump-to-selection
        /*$JumpOpts = ['new' => 'new', 'view' => 'view', 'edit' => 'edit', 'clone' => 'clone'];
        $JumpRefs = [
            'new' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToNew'],
            'view' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToView'],
            'edit' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToEdit'],
            'clone' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToClone']
        ];*/

        // Umwandlung in Contao-konforme Struktur
        /*$JumpOptions = [];
        foreach ($JumpOpts as $key => $value) {
            $JumpOptions[$key] = [
                'value' => $value,
                'label' => $JumpRefs[$key] ?? $value
            ];
        }*/

        // Formularfeld definieren
        $fields['jumpToSelection'] = [
            'name' => 'jumpToSelection',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpWhatsNext'],
            'inputType' => 'select',
            // arrOptions wird direkt verwendet
            'options' => [
                ['value' => '', 'label' => '-'],
                ['value' => 'new', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToNew']],
                ['value' => 'view', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToView']],
                ['value' => 'edit', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToEdit']],
                ['value' => 'clone', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToClone']]
            ],
            'reference' => [
                ['value' => '', 'label' => '-'],
                ['value' => 'new', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToNew']],
                ['value' => 'view', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToView']],
                ['value' => 'edit', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToEdit']],
                ['value' => 'clone', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToClone']]
            ],//$JumpOptions, // Optional für bestimmte Renderer
            //'arrOptions' => $JumpOptions,
            'value' => '', // Vorausgewählter Wert
            'eval' => [
                'mandatory' => true,
                'includeBlankOption' => true,
            ]
        ];

        // here: CALL Hooks with $NewEventData, $currentEventObject, $fields
        if (array_key_exists('buildCalendarEditForm', $GLOBALS['TL_HOOKS']) && is_array($GLOBALS['TL_HOOKS']['buildCalendarEditForm'])) {
            foreach ($GLOBALS['TL_HOOKS']['buildCalendarEditForm'] as $callback) {
                $this->import($callback[0]);
                $arrResult = $this->{$callback[0]}->{$callback[1]}($newEventData, $fields, $currentEventObject, $editID);
                if (is_array($arrResult) && count($arrResult) > 1) {
                    $newEventData = $arrResult['NewEventData'];
                    $fields = $arrResult['fields'];
                }
            }
        }

        $arrWidgets = [];
        // Initialize widgets
        $doNotSubmit = false;
        foreach ($fields as $field) {
            $field['eval']['required'] = $field['eval']['mandatory'] ?? false;

            // from http://pastebin.com/HcjkHLQK
            // via https://github.com/contao/core/issues/5086
            // Convert date formats into timestamps (check the eval setting first -> #3063)
            if ($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit') {
                $rgxp = $field['eval']['rgxp'] ?? '';
                if (($rgxp == 'date' || $rgxp == 'time' || $rgxp == 'datim') && $field['value'] != '') {
                    $objDate = new Date($currentRequest->request->get($field['name']), $GLOBALS['TL_CONFIG'][$rgxp . 'Format']);
                    $field['value'] = $objDate->tstamp;
                }
            }

            $objWidget = match ($field['inputType']) {
                'checkbox' => new FormCheckbox($field),
                'radio' => new FormRadio($field),
                'select' => new FormSelect($field),
                'text' => new FormText($field),
                'textarea' => new FormTextarea($field),
                default => throw new \InvalidArgumentException("Ungültiger inputType: " . $field['inputType']),
            };

            // Validate widget
            if ($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit') {
                $objWidget->validate();
                if ($objWidget->hasErrors()) {
                    $doNotSubmit = true;
                }
            }
            $arrWidgets[$field['name']] = $objWidget;
        }
        $arrWidgets['startDate']->parse();
        $arrWidgets['endDate']->parse();


        // Check, whether the user is allowed to edit past events
        // or the date is in the future
        //$tmpStartDate = strtotime($arrWidgets['startDate']->__get('value'));
        //$tmpEndDate = strtotime($arrWidgets['endDate']->__get('value'));
        $validDate = $this->checkValidDate($newEventData['pid'] ?? 0, $arrWidgets['startDate'], $arrWidgets['endDate']);
        if (!$validDate) {
            // modification of the widget is done in checkValidDate
            $doNotSubmit = true;
        }

        $this->Template->submit = $GLOBALS['TL_LANG']['MSC']['caledit_saveData'];
        $this->Template->calendars = $this->allowedCalendars;

        $hasFrontendUser =  $this->tokenChecker->hasFrontendUser();

        if ((!$doNotSubmit) && ($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit')) {
            // everything seems to be ok, so we can add the POST Data
            // into the Database
            if (!$hasFrontendUser) {
                $newEventData['fe_user'] = ''; // no user
            } else {
                $newEventData['fe_user'] = $this->User->id; // set the FE_user here
            }

            if (is_null($newEventData['published'])) {
                $newEventData['published'] = '';
            }

            if (is_null($newEventData['location'])) {
                $newEventData['location'] = '';
            }

            if ($saveAs === 0) {
                $dbId = $this->saveToDB($newEventData, '', $NewContentData, '');
            } else {
                $dbId = $this->saveToDB($newEventData, $editID, $NewContentData, $contentID);
            }

            // Send Notification EMail
            if ($this->caledit_sendMail) {
                if ($saveAs) {
                    $this->sendNotificationMail($newEventData, '', $this->User->username, '');
                } else {
                    $this->sendNotificationMail($newEventData, $editID, $this->User->username, '');
                }
            }
            $this->generateRedirect($jumpToSelection, $dbId);
        } else {
            // Do NOT Submit
            if ($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit') {
                $this->Template->InfoClass = 'tl_error';
                if ($this->Template->InfoMessage == '') {
                    $this->Template->InfoMessage = $GLOBALS['TL_LANG']['MSC']['caledit_error'];
                } // else: keep the InfoMesage as set before
            }
            $this->Template->fields = $arrWidgets;
        }
    }

    protected function handleDelete($currentEventObject): void
    {
        $this->initializeServices();
        $currentRequest = $this->requestStack->getCurrentRequest();

        $this->strTemplate = $this->caledit_delete_template;
        $this->Template = new FrontendTemplate($this->strTemplate);

        if (!$this->caledit_allowDelete) {
            $this->Template->FatalError = $GLOBALS['TL_LANG']['MSC']['caledit_NoDelete'];
            return;
        }

        // Edit- und Clone-Links erstellen
        $currentUrl = $currentRequest->getUri();
        $this->Template->editRef = str_replace('?delete=', '?edit=', $currentUrl);
        $this->Template->editLabel = $GLOBALS['TL_LANG']['MSC']['caledit_editLabel'];
        $this->Template->editTitle = $GLOBALS['TL_LANG']['MSC']['caledit_editTitle'];

        if ($this->caledit_allowClone) {
            $this->Template->cloneRef = str_replace('?delete=', '?clone=', $currentUrl);
            $this->Template->cloneLabel = $GLOBALS['TL_LANG']['MSC']['caledit_cloneLabel'];
            $this->Template->cloneTitle = $GLOBALS['TL_LANG']['MSC']['caledit_cloneTitle'];
        }

        // Startdatum formatieren
        $dateFormat = $this->container->getParameter('contao.date_format');
        $startDate = Date::parse($dateFormat, $currentEventObject->startDate);

        // Event-Daten in das Template übergeben
        $this->Template->CurrentEventLink = $this->generateEventUrl($currentEventObject);
        $this->Template->CurrentTitle = $currentEventObject->title;
        $this->Template->CurrentDate = $startDate;
        $this->Template->CurrentPublishedInfo = $currentEventObject->published
            ? $GLOBALS['TL_LANG']['MSC']['caledit_publishedEvent']
            : $GLOBALS['TL_LANG']['MSC']['caledit_unpublishedEvent'];

        // Captcha-Feld initialisieren
        $captchaField = [
            'name' => 'captcha',
            'inputType' => 'captcha',
            'eval' => ['mandatory' => true, 'customTpl' => 'form_captcha_calendar-editor'],
        ];

        $arrWidgets = [];
        $doNotSubmit = false;

        $objWidget = new FormCaptcha($captchaField);
        if ($currentRequest->request->get('FORM_SUBMIT') === 'caledit_submit') {
            $objWidget->validate();
            if ($objWidget->hasErrors()) {
                $doNotSubmit = true;
            }
        }
        $arrWidgets[$captchaField['name']] = $objWidget;

        // Template für Delete-Hinweise und -Buttons befüllen
        $this->Template->deleteHint = $GLOBALS['TL_LANG']['MSC']['caledit_deleteHint'];
        $this->Template->submit = $GLOBALS['TL_LANG']['MSC']['caledit_deleteData'];
        $this->Template->deleteWarning = $GLOBALS['TL_LANG']['MSC']['caledit_deleteWarning'];

        // Löschvorgang
        if (!$doNotSubmit && $currentRequest->request->get('FORM_SUBMIT') === 'caledit_submit') {
            $oldEventData = [
                'startDate' => $startDate,
                'title' => $currentEventObject->title,
                'published' => $currentEventObject->published,
            ];

            // Inhalte löschen
            $this->connection->createQueryBuilder()
                ->delete('tl_content')
                ->where('ptable = :ptable')
                ->andWhere('pid = :pid')
                ->setParameter('ptable', 'tl_calendar_events')
                ->setParameter('pid', $currentEventObject->id)
                ->executeStatement();

            // Event selbst löschen
            $this->connection->createQueryBuilder()
                ->delete('tl_calendar_events')
                ->where('id = :id')
                ->setParameter('id', $currentEventObject->id)
                ->executeStatement();

            // Benachrichtigungs-Mail senden
            if ($this->caledit_sendMail) {
                $this->sendNotificationMail($oldEventData, -1, $this->User->username, '');
            }

            $this->generateRedirect('', ''); // Weiterleitung auf Standardseite
        } else {
            if ($currentRequest->request->get('FORM_SUBMIT') === 'caledit_submit') {
                $this->Template->InfoClass = 'tl_error';
                $this->Template->InfoMessage = $GLOBALS['TL_LANG']['MSC']['caledit_error'];
            }
        }

        $this->Template->fields = $arrWidgets;
    }

    protected function handleClone($currentEventObject) : void
    {
        $this->initializeServices();
        $currentRequest = $this->requestStack->getCurrentRequest();

        $this->strTemplate = $this->caledit_clone_template;
        $this->Template = new FrontendTemplate($this->strTemplate);

        $currentID = $currentEventObject->id;
        $currentEventData = array();
        $currentContentData = array();
        $contentID = '';

        // Abrufen der aktuellen URL
        $currentUrl = $currentRequest->getUri();


        // add a "Edit this event"-Link
        $del = str_replace('?clone=', '?edit=', $currentUrl);
        $this->Template->editRef = $del;
        $this->Template->editLabel = $GLOBALS['TL_LANG']['MSC']['caledit_editLabel'];
        $this->Template->editTitle = $GLOBALS['TL_LANG']['MSC']['caledit_editTitle'];

        if ($this->caledit_allowDelete) {
            // add a "Delete this event"-Link
            $del = str_replace('?clone=', '?delete=', $currentUrl);
            $this->Template->deleteRef = $del;
            $this->Template->deleteLabel = $GLOBALS['TL_LANG']['MSC']['caledit_deleteLabel'];
            $this->Template->deleteTitle = $GLOBALS['TL_LANG']['MSC']['caledit_deleteTitle'];
        }

        // get a proper Content-Element
        $this->getContentElements($currentID, $contentID, $currentContentData);
        // get all the data from the current event...
        $this->getEventInformation($currentEventObject, $currentEventData);

        $this->Template->CloneWarning = $GLOBALS['TL_LANG']['MSC']['caledit_CloneWarning'];

        // publishing information
        $published = $currentEventObject->published;
        $this->Template->CurrentPublished = $published;

        if ($published && !$this->caledit_allowPublish) {
            // cloning a published event without publish-rights will result in a lot of unpublished events
            $published = '';
        }

        // current event stored - prepare the formular
        $newDates = [];
        $fields = [];
        $jumpToSelection = '';

        if ($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit') {
            for ($i = 1; $i <= 10; $i++) {
                $newDates['start' . $i] = $currentRequest->request->get('start' . $i);
                $newDates['end' . $i] = $currentRequest->request->get('end' . $i);
            }
            $jumpToSelection = $currentRequest->request->get('jumpToSelection');
        } else {
            for ($i = 1; $i <= 10; $i++) {
                $newDates['start' . $i] = '';
                $newDates['end' . $i] = '';
            }
        }

        // create fields
        for ($i = 1; $i <= 10; $i++) {
            // start dates
            $fields['start' . $i] = array(
                'name' => 'start' . $i,
                'label' => $GLOBALS['TL_LANG']['MSC']['caledit_startdate'],
                'inputType' => 'text',
                'value' => $newDates['start' . $i],
                'eval' => array('rgxp' => 'date', 'mandatory' => false, 'maxlength' => 128, 'decodeEntities' => true)
            );
            // end dates
            $fields['end' . $i] = array(
                'name' => 'end' . $i,
                'label' => $GLOBALS['TL_LANG']['MSC']['caledit_enddate'],
                'inputType' => 'text',
                'value' => $newDates['end' . $i],
                'eval' => array('rgxp' => 'date', 'mandatory' => false, 'maxlength' => 128, 'decodeEntities' => true)
            );

            /*if ($this->caledit_useDatePicker) {
                $this->addDatePicker($fields['start' . $i]);
                $this->addDatePicker($fields['end' . $i]);
            }*/
        }

        $hasFrontendUser =  $this->tokenChecker->hasFrontendUser();

        if (!$hasFrontendUser) {
            $fields['captcha'] = [
                'name' => 'captcha',
                'inputType' => 'captcha',
                'eval' => ['mandatory' => true, 'customTpl' => 'form_captcha_calendar-editor']
            ];
        }

        // create jump-to-selection
        $JumpOpts = ['new', 'view', 'edit', 'clone'];
        $JumpRefs = [
            'new' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToNew'],
            'view' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToView'],
            'edit' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToEdit'],
            'clone' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToClone']
        ];
        $fields['jumpToSelection'] = [
            'name' => 'jumpToSelection',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpWhatsNext'],
            'inputType' => 'select',
            'options' => $JumpOpts,
            'value' => $jumpToSelection,
            'reference' => $JumpRefs,
            'eval' => ['mandatory' => false, 'includeBlankOption' => true, 'maxlength' => 128, 'decodeEntities' => true]
        ];

        // here: CALL Hooks with $NewEventData, $currentEventObject, $fields
        if (array_key_exists('buildCalendarCloneForm', $GLOBALS['TL_HOOKS']) && is_array($GLOBALS['TL_HOOKS']['buildCalendarCloneForm'])) {
            foreach ($GLOBALS['TL_HOOKS']['buildCalendarCloneForm'] as $callback) {
                $this->import($callback[0]);
                $arrResult = $this->{$callback[0]}->{$callback[1]}($newDates, $fields, $currentEventObject, $currentID);
                if (is_array($arrResult) && count($arrResult) > 1) {
                    $newDates = $arrResult['newDates'];
                    $fields = $arrResult['fields'];
                }
            }
        }

        // Initialize widgets
        $arrWidgets = array();
        $doNotSubmit = false;
        foreach ($fields as $field) {
            $field['eval']['required'] = $field['eval']['mandatory'];

            // from http://pastebin.com/HcjkHLQK
            // via https://github.com/contao/core/issues/5086
            // Convert date formats into timestamps (check the eval setting first -> #3063)
            if ($currentRequest->request->get('FORM_SUBMIT') === 'caledit_submit') {
                $rgxp = $field['eval']['rgxp'] ?? '';
                if (($rgxp == 'date' || $rgxp == 'time' || $rgxp == 'datim') && $field['value'] != '') {
                    $objDate = new Date($currentRequest->request->get($field['name']), $GLOBALS['TL_CONFIG'][$rgxp . 'Format']);
                    $field['value'] = $objDate->tstamp;
                }
            }

            $objWidget = match ($field['inputType']) {
                'checkbox' => new FormCheckbox($field),
                'radio' => new FormRadio($field),
                'select' => new FormSelect($field),
                'text' => new FormText($field),
                'textarea' => new FormTextarea($field),
                default => throw new \InvalidArgumentException("Ungültiger inputType: " . $field['inputType']),
            };

            // Validate widget
            if ($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit') {
                $objWidget->validate();
                if ($objWidget->hasErrors()) {
                    $doNotSubmit = true;
                }
            }
            $arrWidgets[$field['name']] = $objWidget;
        }

        // Contao 4.4+: The CalendarFields need to be parsed to activate JS
        for ($i = 1; $i <= 10; $i++) {
            $arrWidgets['start' . $i]->parse();
            $arrWidgets['end' . $i]->parse();
        }

        $allDatesAllowed = $this->allDatesAllowed($currentEventData['pid']);
        for ($i = 1; $i <= 10; $i++) {
            // check the 10 startdates
            $newDate = strtotime($arrWidgets['start' . $i]->__get('value'));

            if ((!$allDatesAllowed) and ($newDate) and ($newDate < time())) {
                $arrWidgets['start' . $i]->addError($GLOBALS['TL_LANG']['MSC']['caledit_formErrorElapsedDate']);
                $doNotSubmit = true;
            }
        }

        $this->Template->submit = $GLOBALS['TL_LANG']['MSC']['caledit_saveData'];

        $hasFrontendUser = $this->tokenChecker->hasFrontendUser();

        if ((!$doNotSubmit) && ($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit')) {
            // everything seems to be ok, so we can add the POST Data
            // into the Database
            if (!$hasFrontendUser) {
                $currentEventData['fe_user'] = ''; // no user
            } else {
                $currentEventData['fe_user'] = $this->User->id; // set the FE_user here
            }

            // for the notification E-Mail
            $originalStart = $currentEventData['startDate'];
            $originalEnd = $currentEventData['endDate'];
            $newDatesMail = '';

            // overwrite User
            if (!$hasFrontendUser) {
                $currentEventData['fe_user'] = ''; // no user
            } else {
                $currentEventData['fe_user'] = $this->User->id; // set the FE_user here
            }
            // Set Publish-Value
            $currentEventData['published'] = $published;
            if (is_null($currentEventData['published'])) {
                $currentEventData['published'] = '';
            }

            // convert the existing timestamps into Strings, so that PutinDB can use them again
            if ($currentEventData['startTime']) {
                $currentEventData['startTime'] = date($GLOBALS['TL_CONFIG']['timeFormat'], $currentEventData['startTime']);
            }
            if ($currentEventData['endTime']) {
                $currentEventData['endTime'] = date($GLOBALS['TL_CONFIG']['timeFormat'], $currentEventData['endTime']);
            }

            for ($i = 1; $i <= 10; $i++) {
                if ($newDates['start' . $i]) {
                    $currentEventData['startDate'] = $newDates['start' . $i];
                    $currentEventData['endDate'] = $newDates['end' . $i];

                    $newDatesMail .= $currentEventData['startDate'];
                    if ($currentEventData['endDate']) {
                        $newDatesMail .= "-" . $currentEventData['endDate'] . " \n";
                    } else {
                        $newDatesMail .= " \n";
                    }
                    $DBid = $this->saveToDB($currentEventData, '', $currentContentData, '');
                }
            }

            // restore values
            $currentEventData['startDate'] = $originalStart;
            $currentEventData['endDate'] = $originalEnd;
            // Send Notification EMail
            if ($this->caledit_sendMail) {
                $this->sendNotificationMail($currentEventData, $currentID, $this->User->username, $newDatesMail);
            }

            // after this: jump to "jumpTo-Page"
            $this->generateRedirect($jumpToSelection, $DBid);
        } else {
            // Do NOT Submit
            if ($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit') {
                $this->Template->InfoClass = 'tl_error';
                $this->Template->InfoMessage = $GLOBALS['TL_LANG']['MSC']['caledit_error'];
            }
            $this->Template->fields = $arrWidgets;
        }
    }

    protected function sendNotificationMail($NewEventData, $editID, $User, $cloneDates) : void
    {
        $this->initializeServices();
        $currentRequest = $this->requestStack->getCurrentRequest();

        $notification = new Email();
        $notification->from = $GLOBALS['TL_ADMIN_EMAIL'];
        $hasFrontendUser = $this->tokenChecker->hasFrontendUser();

        // Abrufen der aktuellen URL
        $host = $currentRequest->getHost();

        if ($editID) {
            if ($editID == -1) {
                $notification->subject = sprintf($GLOBALS['TL_LANG']['MSC']['caledit_MailSubjectDelete'], $host);
            } else {
                $notification->subject = sprintf($GLOBALS['TL_LANG']['MSC']['caledit_MailSubjectEdit'], $host);
            }
        } else {
            $notification->subject = sprintf($GLOBALS['TL_LANG']['MSC']['caledit_MailSubjectNew'], $host);
        }

        $arrRecipients = StringUtil::trimsplit(',', $this->caledit_mailRecipient);
        $mText = $GLOBALS['TL_LANG']['MSC']['caledit_MailEventdata'] . " \n\n";

        if (!$hasFrontendUser) {
            $mText .= $GLOBALS['TL_LANG']['MSC']['caledit_MailUnregisteredUser'] . " \n";
        } else {
            $mText .= sprintf($GLOBALS['TL_LANG']['MSC']['caledit_MailUser'], $User) . " \n";
        }
        $mText .= $GLOBALS['TL_LANG']['MSC']['caledit_startdate'] . ': ' . $NewEventData['startDate'] . " \n";
        $mText .= $GLOBALS['TL_LANG']['MSC']['caledit_enddate'] . ': ' . $NewEventData['endDate'] . "\n";
        $mText .= $GLOBALS['TL_LANG']['MSC']['caledit_starttime'] . ': ' . $NewEventData['startTime'] . "\n";
        $mText .= $GLOBALS['TL_LANG']['MSC']['caledit_endtime'] . ': ' . $NewEventData['endTime'] . "\n";
        $mText .= $GLOBALS['TL_LANG']['MSC']['caledit_title'] . ': ' . $NewEventData['title'] . "\n";
        if ($NewEventData['published']) {
            $mText .= $GLOBALS['TL_LANG']['MSC']['caledit_publishedEvent'];
        } else {
            $mText .= $GLOBALS['TL_LANG']['MSC']['caledit_unpublishedEvent'];
        }

        if ($cloneDates) {
            $mText .= "\n\n" . $GLOBALS['TL_LANG']['MSC']['caledit_MailEventWasCloned'] . "\n" . $cloneDates;
        }

        if (!$this->caledit_allowPublish) {
            $mText .= "\n\n" . $GLOBALS['TL_LANG']['MSC']['caledit_BEUserHint'];
        }
        $notification->text = $mText;

        foreach ($arrRecipients as $rec) {
            $notification->sendTo($rec);
        }
    }
    /**
     * Generate module
     */
    protected function compile() : void
    {
        $this->initializeServices();
        // Add TinyMCE-Stuff to header
        $this->addTinyMCE($this->caledit_tinMCEtemplate);

        // Input über den Symfony-DI-Container beziehen
        $currentRequest = $this->requestStack->getCurrentRequest();

        if ($currentRequest === null) {
            throw new \RuntimeException('No current request available.');
        }

        $editID = $currentRequest->query->get('edit');

        $deleteID = $currentRequest->query->get('delete');
        if ($deleteID) {
            $editID = $deleteID;
        }

        $cloneID = $currentRequest->query->get('clone');
        if ($cloneID) {
            $editID = $cloneID;
        }

        $fatalError = false;

        // Instanz des angemeldeten Frontend-Benutzers erhalten
        $this->User = FrontendUser::getInstance();

        // Kalender abrufen, die für den Benutzer erlaubt sind
        $this->allowedCalendars = $this->getCalendars($this->User);

        $currentEventObject = null; // Standardwert für den Fall, dass kein Event vorhanden ist

        if (count($this->allowedCalendars) == 0 && $editID) {
            $fatalError = true;
            $this->errorString = $GLOBALS['TL_LANG']['MSC']['caledit_NoEditAllowed'];
        } else {
            if (!empty($editID)) {
                $currentEventObject = CalendarEventsModelEdit::findByIdOrAlias($editID);

                // Benutzerrechte prüfen, wenn ein Event vorhanden ist
                $AuthorizedUser = $this->checkUserEditRights($this->User, $editID, $currentEventObject);
                if (!$AuthorizedUser) {
                    // Ein entsprechender Fehlertext wird in der Methode checkUserEditRights gesetzt
                    $fatalError = true;
                }
            }
        }

        // Fatal error, editing not allowed, abort.
        if ($fatalError) {
            $this->strTemplate = $this->caledit_template;
            $this->Template = new FrontendTemplate($this->strTemplate);
            $this->Template->FatalError = $this->errorString;
            return;
        }

        // ok, the user is an authorized user
        if ($deleteID) {
            $this->handleDelete($currentEventObject);
            return;
        }

        if ($cloneID) {
            $this->handleClone($currentEventObject);
            return;
        }

        $this->handleEdit($editID, $currentEventObject);
    }
}
