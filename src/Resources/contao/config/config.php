<?php

/**
 * This file is part of
 *
 * CalendarEditorBundle
 * @copyright  Daniel Gaußmann 2018
 * @author     Daniel Gaußmann (Gausi)
 * @package    Calendar_Editor
 * @license    LGPL-3.0-or-later
 * @see        https://github.com/Diversworld/Contao-CalendarEditor
 *
 * an extension for
 * Contao Open Source CMS
 * (c) Leo Feyer, LGPL-3.0-or-later
 *
 */

/**
 * Front end modules
 */

use Diversworld\CalendarEditorBundle\Hooks\ListAllEventsHook;
use Diversworld\CalendarEditorBundle\Modules\ModuleCalenderEdit;
use Diversworld\CalendarEditorBundle\Modules\ModuleEventEditor;
use Diversworld\CalendarEditorBundle\Modules\ModuleEventReaderEdit;
use Diversworld\CalendarEditorBundle\Modules\ModuleHiddenEventlist;

$GLOBALS['FE_MOD']['events']['calendarEdit']        = ModuleCalenderEdit::class;
$GLOBALS['FE_MOD']['events']['EventEditor']         = ModuleEventEditor::class;
$GLOBALS['FE_MOD']['events']['EventReaderEditLink'] = ModuleEventReaderEdit::class;
$GLOBALS['FE_MOD']['events']['EventHiddenList']     = ModuleHiddenEventlist::class;

//$GLOBALS['TL_HOOKS']['getAllEvents'][] = [ListAllEventsHook::class, 'updateAllEvents'];
$GLOBALS['TL_HOOKS']['listAllEvents'][] = ['Diversworld\CalendarEditorBundle\Hooks\ListAllEventsHook', 'onListAllEvents'];
