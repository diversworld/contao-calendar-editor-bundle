<?php

namespace Diversworld\CalendarEditorBundle\Models;

use Contao\CalendarEventsModel;
use Contao\Date;
use Contao\System;
use Psr\Log\LoggerInterface;

class CalendarEventsModelEdit extends CalendarEventsModel
{
    static private LoggerInterface $logger;

    static function initializeLogger(){
         static::$logger = System::getContainer()->get('monolog.logger.contao.general');
    }

    public static function findByIdOrAlias($ids, array $options = []): ?CalendarEventsModel
    {
        static::initializeLogger();
        static::$logger->debug('CalendarEventsModelEdit findByIdOrAlias');
        static::$logger->debug(' CalendarEventsModelEditids:'.$ids);

        $t = static::$strTable;
        $arrColumns = !is_numeric($ids) ? array("$t.alias=?") : array("$t.id=?");

        static::$logger->debug('CalendarEventsModelEdit arrColumns: '.print_r($arrColumns,true), ['module' => 'findByIdOrAlias']);

        if (!static::isPreviewMode($options)) {
            $time = Date::floorToMinute();
            $arrColumns[] = "$t.published=1 AND ($t.start='' OR $t.start<=$time) AND ($t.stop='' OR $t.stop>$time)";
        }

        $eventObject = static::findOneBy($arrColumns, $ids, $options);

        static::$logger->debug('CalendarEventsModelEdit static::findOneBy' . print_r($eventObject, true), ['module' => 'findByIdOrAlias']);

        //return static::findOneBy($arrColumns, $ids, $options);
        return $eventObject;
    }

}
