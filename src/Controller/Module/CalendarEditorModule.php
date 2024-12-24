<?php

namespace DanielGausi\CalendarEditorBundle\Modules;

use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Module;
use Contao\FrontendTemplate;
use Contao\ModuleModel;
use Symfony\Component\DependencyInjection\Container;

class CalendarEditorModule extends Module
{
    protected $strTemplate = 'cal_default_edit';

    protected function compile(): void
    {
        $this->logger->info(
            'compile() Methode wird ausgeführt',
            ['contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL)]
        );

        $calendarModule = $this->container->get(ModuleCalendarEdit::class);

        $calendarModule->setRequestStack($this->container->get('request_stack'));
        $calendarModule->setCheckAuthService($this->container->get(CheckAuthService::class));

        // Setze relevante Parameter
        $calendarModule->cal_calendar = $this->cal_calendar;
        $calendarModule->cal_startDay = $this->cal_startDay;
        $calendarModule->caledit_add_jumpTo = $this->caledit_add_jumpTo;

        // Generiere Kalender und übertrage die Ausgabe ins Template
        $this->Template->calendar = $calendarModule->generate();

        if (empty($this->Template->calendar)) {
            $this->logger->info(
                'Keine Kalenderdaten verfügbar',
                ['contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL)]
            );
        }
    }
}
