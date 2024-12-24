<?php

namespace Diversworld\CalendarEditorBundle\Controller\Module;

use Contao\BackendTemplate;
use Contao\CalendarEventsModel;
use Contao\Events;
use Contao\Input;
use Contao\StringUtil;
use Contao\System;
use Diversworld\CalendarEditorBundle\Models\CalendarModelEdit;
use Diversworld\CalendarEditorBundle\Services\CheckAuthService;
use Contao\FrontendTemplate;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGenerator;

class ModuleEventReaderEdit extends Events
{
    /**
     * Template
     * @var string
     */
    protected $strTemplate = 'mod_event_ReaderEditLink';

    private ScopeMatcher $scopeMatcher; // Dependency Injection für ScopeMatcher
    private RequestStack $requestStack; // Dependency Injection für RequestStack

    private ?CheckAuthService $checkAuthService = null;

    public function setCheckAuthService(CheckAuthService $checkAuthService): void
    {
        $this->checkAuthService = $checkAuthService;
    }

    protected function initializeServices(): void
    {
        $container = System::getContainer();

        if ($this->checkAuthService === null) {
            $this->checkAuthService = $container->get('Diversworld\CalendarEditorBundle\Services\CheckAuthService');
        }

        $this->scopeMatcher = $container->get('contao.routing.scope_matcher');
        $this->requestStack = $container->get('request_stack');
        //$this->checkAuthService = $container->get(CheckAuthService::class);
    }
    /**
     * Check if the current request is a backend request
     */
    public function isBackend(): bool
    {
        $currentRequest = $this->requestStack->getCurrentRequest();

        return $this->scopeMatcher->isBackendRequest($currentRequest);
    }

    /**
     * Check if the current request is a frontend request
     */
    public function isFrontend(): bool
    {
        $currentRequest = $this->requestStack->getCurrentRequest();

        return $this->scopeMatcher->isFrontendRequest($currentRequest);
    }

	/**
	 * Display a wildcard in the back end
	 * @return string
	 */
	public function generate()
	{
        $request = System::getContainer()->get('request_stack')->getCurrentRequest();

        if ($request && System::getContainer()->get('contao.routing.scope_matcher')->isBackendRequest($request)){
			$objTemplate = new BackendTemplate('be_wildcard');

			$objTemplate->wildcard = '### EVENT READER EDIT LINK ###';
			$objTemplate->title = $this->headline;
			$objTemplate->id = $this->id;
			$objTemplate->link = $this->name;
			$objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

			return $objTemplate->parse();
		}

		// Return if no event has been specified
		if (!Input::get('events')) {
			return '';
		}

		$this->cal_calendar = $this->sortOutProtected(StringUtil::deserialize($this->cal_calendar));

		// Return if there are no calendars
		if (!is_array($this->cal_calendar) || count($this->cal_calendar) < 1)
		{
			return '';
		}
		return parent::generate();
	}

    protected function compile(): void
    {
        $this->Template = new FrontendTemplate($this->strTemplate);
        $this->Template->editRef = '';

        // Token checker service
        $time = time();

        // Überprüfen, ob der Benutzer eingeloggt ist
        $backendUser = $this->security->getUser();

        // Get the current event
        $objEvent = CalendarEventsModel::findPublishedByParentAndIdOrAlias(Input::get('auto_item'), $this->cal_calendar);

        if ($objEvent->numRows < 1) {
            $this->Template->error = $GLOBALS['TL_LANG']['MSC']['caledit_NoEditAllowed'];
            $this->Template->error_class = 'error';
            return;
        }

        // Get calendar with PID
        $calendarModel = CalendarModelEdit::findByPk($objEvent->pid);

        if ($calendarModel === null) {
            return;
        }

        if ($calendarModel->AllowEdit) {
            // Calendar allows editing
            $checkAuth = System::getContainer()->get(CheckAuthService::class);
            if (!$checkAuth instanceof CheckAuthService) {
                throw new \RuntimeException('Invalid API service.');
            }
            $this->checkAutService = $checkAuth;

            $isUserAuthorized = $this->checkAuthService->isUserAuthorized($calendarModel, $this->User);
            $isUserAdmin = $this->checkAuthService->isUserAdmin($calendarModel, $this->User);

            $authorizedElapsedEvents = $this->checkAuthService->isUserAuthorizedElapsedEvents($calendarModel, $this->User);
            $areEditLinksAllowed = $this->checkAuthService->areEditLinksAllowed(
                $calendarModel,
                $objEvent->row(),
                $this->User->id,
                $isUserAdmin,
                $isUserAuthorized
            );

            $strUrl = '';
            if ($areEditLinksAllowed) {
                // Get the JumpToEdit page for this calendar
                $objPage = $this->Database->prepare(
                    "SELECT * FROM tl_page WHERE id=(SELECT caledit_jumpTo FROM tl_calendar WHERE id=?)"
                )->limit(1)
                    ->execute($calendarModel->id);

                if ($objPage->numRows) {
                    // UrlGenerator-Dienst laden
                    $urlGenerator = $this->container->get(UrlGenerator::class);

                    $strUrl = $urlGenerator->generateFrontendUrl($objPage->row(), '');
                }

                $this->Template->editRef = $strUrl . '?edit=' . $objEvent->id;
                $this->Template->editLabel = $GLOBALS['TL_LANG']['MSC']['caledit_editLabel'];
                $this->Template->editTitle = $GLOBALS['TL_LANG']['MSC']['caledit_editTitle'];

                if ($this->caledit_showCloneLink) {
                    $this->Template->cloneRef = $strUrl . '?clone=' . $objEvent->id;
                    $this->Template->cloneLabel = $GLOBALS['TL_LANG']['MSC']['caledit_cloneLabel'];
                    $this->Template->cloneTitle = $GLOBALS['TL_LANG']['MSC']['caledit_cloneTitle'];
                }
                if ($this->caledit_showDeleteLink) {
                    $this->Template->deleteRef = $strUrl . '?delete=' . $objEvent->id;
                    $this->Template->deleteLabel = $GLOBALS['TL_LANG']['MSC']['caledit_deleteLabel'];
                    $this->Template->deleteTitle = $GLOBALS['TL_LANG']['MSC']['caledit_deleteTitle'];
                }

            } else {
                if (!$isUserAuthorized) {
                    $this->Template->error_class = 'error';
                    $this->Template->error = $GLOBALS['TL_LANG']['MSC']['caledit_UnauthorizedUser'];
                    return;
                }

                if ($objEvent->disable_editing) {
                    // Event is locked in the backend
                    $this->Template->error = $GLOBALS['TL_LANG']['MSC']['caledit_DisabledEvent'];
                    $this->Template->error_class = 'error';
                } else {
                    if (!$authorizedElapsedEvents) {
                        // User is authorized, but event has elapsed
                        $this->Template->error = $GLOBALS['TL_LANG']['MSC']['caledit_NoPast'];
                    } else {
                        // User is NOT authorized at all (e.g., only the creator can edit it)
                        $this->Template->error = $GLOBALS['TL_LANG']['MSC']['caledit_OnlyUser'];
                    }
                    $this->Template->error_class = 'error';
                }
            }
        } else {
            $this->Template->error_class = 'error';
            $this->Template->error = $GLOBALS['TL_LANG']['MSC']['caledit_NoEditAllowed'];
        }
    }

}

?>
