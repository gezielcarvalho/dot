<?php /* CALENDAR $Id$ */
if (!defined('DP_BASE_DIR')) {
  die('You should not access this file directly.');
}

$AppUI->savePlace();
global $locale_char_set;

require_once($AppUI->getModuleClass('tasks'));

// retrieve any state parameters
if (isset($_REQUEST['company_id'])) {
	$AppUI->setState('CalIdxCompany', intval($_REQUEST['company_id']));
}
$company_id = ($AppUI->getState('CalIdxCompany') !== NULL ? $AppUI->getState('CalIdxCompany') 
               : $AppUI->user_company);

$event_filter = $AppUI->checkPrefState('CalIdxFilter', @$_REQUEST['event_filter'], 'EVENTFILTER', 
                                       'my');

// get the passed timestamp (today if none)
$date = dPgetCleanParam($_GET, 'date', null);

// establish the focus 'date'
$this_week = new CDate($date);
$dd = $this_week->getDay();
$mm = $this_week->getMonth();
$yy = $this_week->getYear();

// Debug: record locale/weekday constants and computed inputs
@file_put_contents(DP_BASE_DIR . '/tmp/date_debug.log', json_encode(array('ts'=>date('c'), 'file'=>'week_view.php::init', 'LOCALE_FIRST_DAY'=>defined('LOCALE_FIRST_DAY')?LOCALE_FIRST_DAY:null, 'DATE_CALC_BEGIN_WEEKDAY'=>defined('DATE_CALC_BEGIN_WEEKDAY')?DATE_CALC_BEGIN_WEEKDAY:null, 'dd'=>$dd,'mm'=>$mm,'yy'=>$yy)) . PHP_EOL, FILE_APPEND);

// prepare time period for 'events' using LOCALE_FIRST_DAY
// compute offset from desired first day and build week range
$firstDayOfWeek = defined('LOCALE_FIRST_DAY') ? LOCALE_FIRST_DAY : 0; // 0=Sun,1=Mon
$currentWeekday = $this_week->getDayOfWeek(); // 0=Sun..6=Sat
$offset = ($currentWeekday - $firstDayOfWeek + 7) % 7;
$week_time = new CDate($this_week);
$first_time = new CDate($this_week);
$first_time->addDays(-$offset);
$first_time->setTime(0, 0, 0);

// Debug: record computed week start and week_time
@file_put_contents(DP_BASE_DIR . '/tmp/date_debug.log', json_encode(array('ts'=>date('c'), 'file'=>'week_view.php::week_times', 'first_time'=>$first_time->format(FMT_TIMESTAMP_DATE), 'week_time'=>$week_time->format(FMT_TIMESTAMP_DATE), 'LOCALE_FIRST_DAY'=>$firstDayOfWeek)) . PHP_EOL, FILE_APPEND);

$last_time = new CDate($first_time);
$last_time->addDays(6);
$last_time->setTime(23, 59, 59);

// prev/next week references
$prev_week = new CDate($week_time);
$prev_week->addDays(-7);
$next_week = new CDate($week_time);
$next_week->addDays(7);

$taskObj = new CTask();
$tasks = $taskObj->getTasksForPeriod($first_time, $last_time, $company_id);
$eventObj = new CEvent();
$events = $eventObj->getEventsForPeriod($first_time, $last_time);

$links = array();

// assemble the links for the tasks
require_once(DP_BASE_DIR.'/modules/calendar/links_tasks.php');
getTaskLinks($first_time, $last_time, $links, 50, $company_id);

// assemble the links for the events
require_once(DP_BASE_DIR.'/modules/calendar/links_events.php');
getEventLinks($first_time, $last_time, $links, 50);

$cal_week = new CMonthCalendar($date);
$cal_week->setEvents($links);

// get the list of visible companies
$company = new CCompany();
$companies = $company->getAllowedRecords($AppUI->user_id, 'company_id,company_name', 
                                         'company_name');
$companies = arrayMerge(array('0'=>$AppUI->_('All')), $companies);

// setup the title block
$titleBlock = new CTitleBlock('Week View', 'myevo-appointments.png', $m, "$m.$a");
$titleBlock->addCrumb('?m=calendar&amp;date='.$this_week->format(FMT_TIMESTAMP_DATE), 'month view');
$titleBlock->addCell($AppUI->_('Company').':');
$titleBlock->addCell(arraySelect($companies, 'company_id', 
                                 'onchange="javascript:document.pickCompany.submit()" class="text"', 
                                 $company_id), '', 
                     ('<form action="' . $_SERVER['REQUEST_URI'] 
                      . '" method="post" name="pickCompany">'), '</form>');
$titleBlock->addCell($AppUI->_('Event Filter') . ':');
$titleBlock->addCell(arraySelect($event_filter_list, 'event_filter', 
                                 'onchange="javascript:document.pickFilter.submit()" class="text"',
                                 $event_filter, true), '', 
                     ('<form action="' . $_SERVER['REQUEST_URI'] 
                      . '" method="post" name="pickFilter">'), '</form>');
$titleBlock->show();
?>

<style type="text/css">
TD.weekDay  {
	height:120px;
	vertical-align: top;
	padding: 1px 4px 1px 4px;
	border-bottom: 1px solid #ccc;
	border-right: 1px solid  #ccc;
	text-align: left;
}
</style>

<table border="0" cellspacing="1" cellpadding="2" width="100%" class="motitle">
<tr>
	<td>
		<a href="<?php 
echo ('?m=calendar&amp;a=week_view&amp;date='.$prev_week->format(FMT_TIMESTAMP_DATE)); ?>">
		<?php echo dPshowImage(dPfindImage('prev.gif'), 16, 16, $AppUI->_('previous week')); ?>
		</a>
	</td>
	<th width="100%">
		<span style="font-size:12pt"><?php 
echo ($AppUI->_('Week') . ' ' 
      . htmlentities($week_time->format('%V - %Y'), ENT_COMPAT, $locale_char_set)); ?></span>
	</th>
	<td>
		<a href="<?php 
echo ('?m=calendar&amp;a=week_view&amp;date=' . $next_week->format(FMT_TIMESTAMP_DATE)); ?>">
		<?php echo dPshowImage(dPfindImage('next.gif'), 16, 16, $AppUI->_('next week')); ?>
		</a>
	</td>
</tr>
</table>

<table border="0" cellspacing="1" cellpadding="2" width="100%" style="margin:4px;background-color:white">
<?php
$column = 0;
// ensure we start display on the beginning of the week (Monday)
$show_day = (is_object($week_time) ? new CDate($week_time) : new CDate($this_week));

$today = new CDate();
$today = $today->format(FMT_TIMESTAMP_DATE);

for ($i=0; $i < 7; $i++) {
	$dayStamp = $show_day->format(FMT_TIMESTAMP_DATE);

	$day  = $show_day->getDay();
	$href = ('?m=calendar&a=day_view&date=' . $dayStamp . '&tab=0');
	
	
	$s = '';
	if ($column == 0) {
		$s .= "\t<tr>\n";
	}
	$s .= "\t\t" . '<td class="weekDay" style="width:50%;">' ."\n";
	
	$s .= "\t\t" . '<table style="width:100%;border-spacing:0;">' ."\n";
	$s .= ("\t\t\t" . '<tr><td align="' . (($column == 0) ? 'left' : 'right') 
	       . '"><a href="' . htmlspecialchars($href) . '">');
	$s .= (($dayStamp == $today) ? '<span style="color:red">' : '');
	$day_string = trim('<strong>' 
	                   . htmlentities($show_day->format('%d'), ENT_COMPAT, $locale_char_set) 
	                   . '</strong>');
	$day_name = trim(htmlentities($show_day->format("%A"), ENT_COMPAT, $locale_char_set));
	$s .= trim(($column == 0) ? ($day_string . ' ' . $day_name) : ($day_name . ' ' . $day_string));
	
	$s .= (($dayStamp == $today) ? '</span>' : '');
	$s .= "\t\t\t" . '</a></td></tr>' ."\n";
	
	$s .= "\t\t\t" . '<tr><td>' . $cal_week->_drawBirthdays($dayStamp) . $cal_week->_drawEvents($dayStamp) . '</td></tr>' ."\n";
	
	$s .= "\t\t</table>\n";
	
	$s .= "\t\t</td>\n";
	if ($column == 1) {
		$s .= "\t</tr>\n";
	}
	$column = 1 - $column;
	
	// select next day
	$show_day->addSeconds(24*3600);
	echo $s;
}
?>
<tr>
	<td colspan="2" align="right" bgcolor="#efefe7">
		<a href="?m=calendar&amp;a=week_view"><?php echo $AppUI->_('today');?></a>
	</td>
</tr>
</table>
