<?php
if (!defined('DP_BASE_DIR')) {
  die('You should not access this file directly.');
}

$AppUI->savePlace();

$do_report = (bool)dPgetParam($_POST, 'do_report', true);
$log_start_date = dPgetCleanParam($_POST, 'log_start_date', 0);
$log_end_date = dPgetCleanParam($_POST, 'log_end_date', 0);
$log_all = (bool)dPgetParam($_POST, 'log_all', true);
$use_period = (int)dPgetParam($_POST, 'use_period', 0);
$show_orphaned = (int)dPgetParam($_POST, 'show_orphaned', 0);
$display_week_hours = (int)dPgetParam($_POST, 'display_week_hours', 0);
$max_levels = dPgetCleanParam($_POST, 'max_levels', '');
$log_userfilter = (int)dPgetParam($_POST, 'log_userfilter', 0);
$company_id = dPgetCleanParam($_POST, 'company_id', 'all');
$project_id = dPgetCleanParam($_POST, 'project_id', 'all');

require_once ($AppUI->getModuleClass('projects'));
require_once ($AppUI->getModuleClass('tasks'));

$proj = new CProject();
// filtering by companies
$projects = $proj->getAllowedRecords($AppUI->user_id, 'project_id,project_name', 'project_name');
$projFilter = arrayMerge(array('all' => $AppUI->_('All Projects')), $projects);

$durnTypes = dPgetSysVal('TaskDurationType');
$taskPriority = dPgetSysVal('TaskPriority');

// create Date objects from the datetime fields
$start_date = intval($log_start_date) ? new CDate($log_start_date) : new CDate();
$end_date = intval($log_end_date) ? new CDate($log_end_date) : new CDate();
$now = new CDate();

if (!$log_start_date) {
	$start_date->subtractSpan(new Date_Span('14,0,0,0'));
}
$end_date->setTime(23, 59, 59);

// Helpers to render repetitive HTML blocks more readably
function render_ass_form_start($user_id, $label)
{
	ob_start();
	?>
	<tr>
		<td style="background: #D0D0D0; color: #000000; font-weight: bold;" nowrap="nowrap">
			<form name="assFrm<?php echo (int)$user_id; ?>" action="index.php?m=tasks&a=tasksperuser" method="post">
			<input type="hidden" name="del" value="1" />
			<input type="hidden" name="rm" value="0" />
			<input type="hidden" name="store" value="0" />
			<input type="hidden" name="dosql" value="do_task_assign_aed" />
			<input type="hidden" name="user_id" value="<?php echo (int)$user_id; ?>" />
			<input type="hidden" name="hassign" />
			<input type="hidden" name="htasks" />
			<input onclick="javascript:checkAll('<?php echo (int)$user_id; ?>');" type="checkbox" name="master" value="true"/>
		</td>
		<td colspan="6" style="background: #D0D0D0; color: #000000; font-weight: bold;" nowrap="nowrap">
			<a href="index.php?m=calendar&amp;a=day_view&amp;user_id=<?php echo (int)$user_id; ?>&amp;tab=1"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></a>
		</td>
	<?php
	return ob_get_clean();
}

function render_empty_week_cells($display_week_hours, $from, $to)
{
	$out = '';
	$wx = weekCells($display_week_hours, $from, $to);
	for ($w = 0; $w <= $wx; $w++) {
		$out .= "\t\t\t<td style=\"background: #D0D0D0;\">&nbsp;</td>\n";
	}
	return $out;
}

function render_percentage_options($selected = 30)
{
	$out = '';
	for ($i = 0; $i <= 100; $i += 5) {
		$sel = ($i == $selected) ? ' selected="selected"' : '';
		$out .= "\t\t\t\t\t\t<option value=\"$i\"$sel>$i%</option>\n";
	}
	return $out;
}

// Normalize a date to the Monday (start) of its week at 00:00:00
function week_start_monday($date)
{
	$d = new CDate($date);
	$dow = $d->getDayOfWeek(); // expected 0=Sun,1=Mon,...6=Sat
	$offset = ($dow + 6) % 7; // days since Monday
	for ($i = 0; $i < $offset; $i++) {
		$prev = $d->getPrevDay();
		$d = new CDate($prev->format('%Y-%m-%d 00:00:00'));
	}
	return $d;
}

// Normalize a date to the Sunday (end) of its week at 23:59:59
function week_end_sunday($date)
{
	$d = new CDate($date);
	$dow = $d->getDayOfWeek(); // expected 0=Sun,1=Mon,...6=Sat
	$offset = ($dow + 6) % 7; // days since Monday
	$days_to_sunday = 6 - $offset;
	for ($i = 0; $i < $days_to_sunday; $i++) {
		$next = $d->getNextDay();
		$d = new CDate($next->format('%Y-%m-%d 23:59:59'));
	}
	return $d;
}
?>

<script language="javascript">
<?php
// security improvement:
// some javascript functions may not appear on client side in case of user not having write permissions
// else users would be able to arbitrarily run 'bad' functions
if ($canEdit) {
?>
function checkAll(user_id) {
	var f = eval("document.assFrm" + user_id);
	var cFlag = f.master.checked ? false : true;
	for (var i=0; i< f.elements.length; i++) {
		var e = f.elements[i];
		// only if it's a checkbox.
		if (e.type == "checkbox" && e.checked == cFlag && e.name != "master") {
			e.checked = !e.checked;
		}
	}
}

function chAssignment(user_id, rmUser, del) {
	var f = eval("document.assFrm" + user_id);
	var fl = f.add_users.length - 1;
	var c = 0;
	var a = 0;
	
	f.hassign.value = "";
	f.htasks.value = "";
	
	// harvest all checked checkboxes (tasks to process)
	for (var i=0; i< f.elements.length; i++) {
		var e = f.elements[i];
		// only if it's a checkbox.
		if (e.type == "checkbox" && e.checked == true && e.name != "master") {
			c++;
			f.htasks.value = f.htasks.value + "," + e.value;
		}
	}
	
	// harvest all selected possible User Assignees
	for (fl; fl > -1; fl--) {
		if (f.add_users.options[fl].selected) {
			a++;
			f.hassign.value = "," + f.hassign.value + "," + f.add_users.options[fl].value;
		}
	}
	
	if (del == true) {
		if (c == 0) {
			alert ("<?php echo $AppUI->_('Please select at least one Task!', UI_OUTPUT_JS); ?>");
		} else if (confirm("<?php echo $AppUI->_('Are you sure you want to unassign the User from Task(s)?', UI_OUTPUT_JS); ?>")) {
			f.del.value = 1;
			f.rm.value = rmUser;
			f.user_id.value = user_id;
			f.submit();
		}
	} else if (c == 0) {
		alert ("<?php echo $AppUI->_('Please select at least one Task!', UI_OUTPUT_JS); ?>");
	} else if (a == 0) {
		alert ("<?php echo $AppUI->_('Please select at least one Assignee!', UI_OUTPUT_JS); ?>");
	} else {
		f.rm.value = rmUser;
		f.del.value = del;
		f.user_id.value = user_id;
		f.submit();
	}
}

function chPriority(user_id) {
	var f = eval("document.assFrm" + user_id);
	var c = 0;
	
	f.htasks.value = "";
	
	// harvest all checked checkboxes (tasks to process)
	for (var i=0; i< f.elements.length; i++) {
		var e = f.elements[i];
		// only if it's a checkbox.
		if (e.type == "checkbox" && e.checked == true && e.name != "master") {
			c++;
			f.htasks.value = f.htasks.value +","+ e.value;
		}
	}
	
	if (c == 0) {
		alert ("<?php echo $AppUI->_('Please select at least one Task!', UI_OUTPUT_JS); ?>");
	} else {
		f.rm.value = 0;
		f.del.value = 0;
		f.store.value = 0;
		f.chUTP.value = 1;
		f.user_id.value = user_id;
		f.submit();
	}
}
<?php } ?>
</script>
<form name="editFrm" action="index.php?m=tasks&a=tasksperuser" method="post">
<input type="hidden" name="project_id" value="<?php echo $project_id; ?>" />
<input type="hidden" name="company_id" value="<?php echo $company_id; ?>" />
<input type="hidden" name="report_type" value="<?php echo $report_type; ?>" />

<table cellspacing="0" cellpadding="4" border="0" width="100%" class="std">
<tr>
	<td align="right" width="1%" nowrap="nowrap">
		<label for="log_start_date"><?php echo $AppUI->_('For period'); ?>:</label>
		<input type="date" name="log_start_date" id="log_start_date" value="<?php 
echo $start_date->format(FMT_DATE_HTML5); ?>" class="text" />
		<br /><br />
		<label for="log_end_date"><?php echo $AppUI->_('to'); ?>:</label>
		<input type="date" name="log_end_date" id="log_end_date" value="<?php 
echo (($end_date) ? $end_date->format(FMT_DATE_HTML5) : ''); ?>" class="text" />
		<br />
	</td>
	<td width="1%" nowrap="nowrap">
		<?php
echo arraySelect(dPgetUsers(), 'log_userfilter', 'class="text" style="width: 200px"', 
                 $log_userfilter); 
echo "\n<br />\n";
echo $AppUI->_('Projects') . ':';
echo arraySelect($projFilter, 'project_id', 'size=1 class=text', $project_id, false);
echo "\n<br />\n";
echo $AppUI->_('Levels to display'); 
?>
		<input type="text" name="max_levels" size="10" maxlength="3" value="<?php 
echo $max_levels; ?>" />
	</td>
	<td width="1%" nowrap="nowrap">
		<input type="checkbox" name="display_week_hours" id="display_week_hours"<?php 
echo (($display_week_hours) ? ' checked="checked"' : ''); ?> />
		<label for="display_week_hours"><?php 
echo $AppUI->_('Display allocated hours/week'); ?></label>
		<br />
		<input type="checkbox" name="use_period" id="use_period"<?php 
echo (($use_period) ? ' checked="checked"' : ''); ?> />
		<label for="use_period"><?php echo $AppUI->_('Use the period'); ?></label>
		<br />
		<input type="checkbox" name="show_orphaned" id="show_orphaned"<?php 
echo (($show_orphaned) ? ' checked="checked"' : ''); ?> />
		<label for="show_orphaned"><?php echo $AppUI->_('Hide orphaned tasks'); ?></label>
	</td>
	<td align="left" nowrap="nowrap">
		<input class="button" type="submit" name="do_report" value="<?php 
echo $AppUI->_('submit'); ?>" />
	</td>
</tr>
</table>
</form>
<?php
echo $AppUI->_('P') . '&nbsp;=&nbsp;' . $AppUI->_('User specific Task Priority');
if ($do_report) {
	// get Users with all Allocation info (e.g. their freeCapacity)
	$tempoTask = new CTask();
	$userAlloc = $tempoTask->getAllocation("user_id");
	
	// Let's figure out which users we have
	$sql = new DBQuery;
	$sql->addTable('users');
	$sql->addQuery('user_id, user_username');
	if ($log_userfilter!=0) {
		$sql->addWhere('user_id = ' . $log_userfilter);
	}
	$sql->addOrder('user_username');
	$user_list = $sql->loadHashList('user_id');
	$sql->clear();
	
	$ss = $start_date->format(FMT_DATETIME_MYSQL);
	$se = $end_date->format(FMT_DATETIME_MYSQL);
	
	$sql->addTable('tasks', 't');
	$sql->innerJoin('projects', 'p', 'p.project_id = t.task_project');
	if ($log_userfilter !=0) {
		$sql->innerJoin('user_tasks', 'ut', 'ut.task_id = t.task_id');
	}
	$sql->addQuery('t.*');
	if ($use_period) {
		$sql->addWhere("((task_start_date IS NOT NULL AND task_start_date != '0000-00-00 00:00:00' AND task_start_date >= '$ss' AND task_start_date <= '$se') "
				." OR (task_end_date IS NOT NULL AND task_end_date != '0000-00-00 00:00:00' AND task_end_date <= '$se' AND task_end_date >= '$ss'))");
	}

	$sql->addWhere('task_percent_complete < 100');
	if ($project_id != 'all') {
		$sql->addWhere('t.task_project = \''. $project_id . '\'');
	}
		if ($company_id != 'all') {
		$sql->addWhere("p.project_company='$company_id'");
	}
	if ($log_userfilter !=0) {
		$sql->addWhere('ut.user_id = ' . $log_userfilter);
	}
	
	$task = new CTask();
	$allowedTasks = $task->getAllowedSQL($AppUI->user_id, 't.task_id');
	if (count($allowedTasks)) {
		$sql->addWhere(implode(' AND ', $allowedTasks));
	}
	$allowedChildrenTasks = $task->getAllowedSQL($AppUI->user_id, 't.task_parent');
	if (count($allowedChildrenTasks)) {
		$sql->addWhere(implode(' AND ', $allowedChildrenTasks));
	}
	
	// Now add the required restrictions.
	$proj->setAllowedSQL($AppUI->user_id, $sql, null, 'p');
	$sql->addOrder('task_project, task_end_date, task_start_date');
	
	$task_list_hash = $sql->loadHashList('task_id');
	$task_list = array();
	$task_assigned_users = array();
	$user_assigned_tasks = array();
	
	foreach ($task_list_hash as $task_id => $task_data) {
		$task = new CTask();
		$task->bind($task_data);
		$task_users = $task->getAssignedUsers();
		foreach (array_keys($task_users) as $uid) {
		  $user_assigned_tasks[$uid][] = $task_id;
		}
		$task_assigned_users[$task_id] = $task_users;
		$task_list[$task_id] = $task;
	}
	
	$user_usage	= array();
	$task_dates	= array();

	$actual_date = $start_date;
	$days_header = ''; // we will save days title here
	
	$max_levels = (($max_levels == '' || intval($max_levels) < 0) ? -1 : intval($max_levels));
	$max_levels = (($max_levels == 0) ? 1 : $max_levels);
	
	if (count($task_list) == 0) {
		echo '<p>' . $AppUI->_('No data available') .'</p>';
	} else {
		$sss = $ss;
		$sse = $se;
		if (!$use_period) {	
			$sss = $sse = 0; 
			if ($display_week_hours) {
				foreach ($task_list as $t) {
					$sss = ((!($sss) || $t->task_start_date < $sss) ? $t->task_start_date : $sss);
					$sse = ((!($sse) || $t->task_end_date > $sse) ? $t->task_end_date : $sse);
				}
			}
			
		}

		//Display Users and thier related Tasks.
?>
<center>
	<table width="100%" border="0" cellpadding="2" cellspacing="1" class="std">
		<tr>
			<th nowrap="nowrap"></th>
			<th nowrap="nowrap"><?php echo $AppUI->_('P'); ?></th>
			<th nowrap="nowrap"><?php echo $AppUI->_('Task'); ?></th>
			<th nowrap="nowrap"><?php echo $AppUI->_('Project'); ?></th>
			<th nowrap="nowrap"><?php echo $AppUI->_('Duration'); ?></th>
			<th nowrap="nowrap"><?php echo $AppUI->_('Start'); ?></th>
			<th nowrap="nowrap"><?php echo $AppUI->_('End'); ?></th>
<?php
		if ($display_week_hours) {
			echo (weekDates($sss, $sse));
		}
?>
			<th nowrap="nowrap"><?php echo $AppUI->_('Current Assignees'); ?></th>
			<th nowrap="nowrap"><?php echo $AppUI->_('Possible Assignees'); ?></th>
		</tr>
<?php 
		foreach ($user_list as $user_id => $user_data) {
			
			// count tasks per user;
			$z=0;
			foreach ($task_list as $task) {
				if (isMemberOfTask($task_list, $user_id, $task)) {
					$z++;
				}
			}
?>
		<tr>
			<td style="background: #D0D0D0;color: #000000;font-weight: bold;" nowrap="nowrap">
				<form name="assFrm<?php 
					echo($user_id); ?>" action="?m=tasks&amp;a=tasksperuser" method="post">
				<input type="hidden" name="chUTP" value="0" />
				<input type="hidden" name="del" value="1" />
				<input type="hidden" name="rm" value="0" />
				<input type="hidden" name="store" value="0" />
				<input type="hidden" name="dosql" value="do_task_assign_aed" />
				<input type="hidden" name="user_id" value="<?php echo($user_id); ?>" />
				<input type="hidden" name="hassign" />
				<input type="hidden" name="htasks" />
				<input onclick="javascript:checkAll('<?php 
			echo($user_id); ?>');" type="checkbox" name="master" value="true"/>
			</td>
			<td colspan="6" style="background: #D0D0D0;color: #000000;font-weight: bold;" nowrap="nowrap">
				<a href="index.php?m=calendar&amp;a=day_view&amp;user_id=<?php 
			echo($user_id); ?>&tab=1"><?php echo (isset($userAlloc[$user_id]['userFC']) ? dPformSafe($userAlloc[$user_id]['userFC']) : ''); ?></a>
			</td>
<?php 
			$wx = (weekCells($display_week_hours, $sss, $sse));
			for ($w=0; $w <=$wx; $w++) {
?>
			<td style="background: #D0D0D0;">&nbsp;</td>
<?php
			}
?>
			<td bgcolor="#D0D0D0">
				<table width="100%"><tr>
					<td align="left">
						<a href="javascript:chAssignment(<?php echo($user_id); ?>, 0, 1);">
						<?php 
			echo dPshowImage(dPfindImage('remove.png', 'tasks'), 16, 16, 'Unassign User', 
			                'Unassign User from Task'); ?>
						</a>&nbsp;
						<a href="javascript:chAssignment(<?php echo($user_id); ?>, 1, 0);">
						<?php 
			echo dPshowImage(dPfindImage('exchange.png', 'tasks'), 24, 16, 'Hand Over', 
			                'Unassign User from Task and handing-over to selected Users'); ?>
						</a>&nbsp;
						<a href="javascript:chAssignment(<?php echo($user_id); ?>, 0, 0);">
						<?php 
			echo dPshowImage(dPfindImage('add.png', 'tasks'), 16, 16, 'Assign Users', 
			                'Assign selected Users to selected Tasks'); ?>
						</a>
					</td>
					<td align="center">
						<select class="text" name="percentage_assignment" title="<?php
			echo $AppUI->_('Assign with Percentage'); ?>">
<?php echo render_percentage_options(); ?>
						</select>
					</td>
					<td align="center">
						<?php 
			echo arraySelect($taskPriority, 'user_task_priority', 
			                 ('onchange="javascript:chPriority(' . $user_id 
			                  . ');" size="1" class="text" title="' 
			                  . $AppUI->_('Change User specific Task Priority of selected Tasks') 
			                  . '"'), 0, true); ?>
					</td>
				</tr></table>
			</td>
		</tr>
<?php
			$tmptasks = '';
			$actual_date = $start_date;
			
			$zi = 0;
			foreach ($task_list as $task) {
				if ($task->task_id == $task->task_parent) {
					if (isMemberOfTask($task_list, $user_id, $task)) {
						$tmptasks .= displayTask($task_list, $task, 0, $display_week_hours, $sss, 
						                         $sse,  $user_id);
						// Get children
						$tmptasks .= doChildren($task_list, $task->task_id, $user_id, 
						                        1, $max_levels, $display_week_hours, $sss, $sse);
					} else {
						// we have to process children task the user
						// is member of, but member of their parent task.
						// Also show the parent task then before the children.
						$tmpChild = '';
						$tmpChild = doChildren($task_list, $task->task_id, $user_id, 
						                     1, $max_levels, $display_week_hours, $sss, $sse);
						if ($tmpChild > '') {
							$tmptasks .= displayTask($task_list, $task, 0, $display_week_hours, 
							                         $sss, $sse, $user_id);
							$tmptasks .= $tmpChild;
						}
					}
				}
			}
			
			$tmptasks .= "</form>";
			echo $tmptasks;
		}

		// show orphaned tasks
		if (!$show_orphaned) {
			$user_id = 0; //reset user id to zero (create new object - no user)
?>
		<tr>
			<td style="background: #D0D0D0;color: #000000;font-weight: bold;" nowrap="nowrap">
				<form name="assFrm<?php 
					echo($user_id); ?>" action="index.php?m=tasks&amp;a=tasksperuser" method="post">
				<input type="hidden" name="del" value="1" />
				<input type="hidden" name="rm" value="0" />
				<input type="hidden" name="store" value="0" />
				<input type="hidden" name="dosql" value="do_task_assign_aed" />
				<input type="hidden" name="user_id" value="<?php echo($user_id); ?>" />
				<input type="hidden" name="hassign" />
				<input type="hidden" name="htasks" />
				<input onclick="javascript:checkAll('<?php 
			echo($user_id); ?>');" type="checkbox" name="master" value="true"/>
			</td>
	<?php
				echo render_ass_form_start($user_id, $AppUI->_('Orphaned Tasks'));
				echo render_empty_week_cells($display_week_hours, $sss, $sse);
	?>
			<td bgcolor="#D0D0D0">
				<table width="100%"><tr>
					<td align="left">
						<a href="javascript:chAssignment(<?php echo($user_id); ?>, 0, 0);">
						<?php 
			echo dPshowImage(dPfindImage('add.png', 'tasks'), 16, 16, 'Assign Users', 
		    	            'Assign selected Users to selected Tasks'); ?>
						</a>
					</td>
					<td align="center">
						<select class="text" name="percentage_assignment" title="<?php
			echo $AppUI->_('Assign with Percentage'); ?>">
<?php echo render_percentage_options(); ?>
						</select>
					</td>
					<td align="center">
						<?php 
			echo arraySelect($taskPriority, 'user_task_priority', 
			                 ('onchange="javascript:chPriority(' . $user_id 
			                  . ');" size="1" class="text" title="' 
			                  . $AppUI->_('Change User specific Task Priority of selected Tasks') 
			                  . '"'), 0, true); ?>
					</td>
				</tr></table>
<?php 
			$orphTasks = array_diff(array_map("getOrphanedTasks",$task_list), array(NULL));
			
			$tmptasks='';
			$actual_date = $start_date;
			
			$zi = 0;
			foreach ($orphTasks as $task) {
				$tmptasks.=displayTask($orphTasks,$task,0,$display_week_hours,$sss,$sse, $user_id);
				// do we need to get the children?
				//$tmptasks.=doChildren($orphTasks,$task->task_id,$user_id,1,$max_levels,$display_week_hours,$sss,$sse);
			}
			echo ($tmptasks . '</form>');
		}	// end of show orphaned tasks
	}//end of else
}
?>
			</td>
		</tr>
	</table>
</center>

<?php
function doChildren($list, $id, $uid, $level, $maxlevels, $display_week_hours, $ss, $se) {
	$tmp = "";
	if ($maxlevels == -1 || $level < $maxlevels) {
		foreach ($list as $task) {
			if (($task->task_parent == $id) && $task->task_id != $task->task_parent) {
				// we have a child, do we have the user as a member?
				if (isMemberOfTask($list, $uid, $task)) {
					$tmp .= displayTask($list, $task, $level, $display_week_hours, $ss, $se, $uid);
					$tmp .= doChildren($list, $task->task_id, $uid, $level + 1, $maxlevels, 
					                   $display_week_hours, $ss, $se);
				}
			}
		}
	}
	return $tmp;
}

function isMemberOfTask($list, $user_id, $task) {
	global $user_assigned_tasks;
	
	if (isset($user_assigned_tasks[$user_id]) 
	    && in_array($task->task_id, $user_assigned_tasks[$user_id])) {
		return true;
	}
	return false;
}

function displayTask($list, $task, $level, $display_week_hours, $fromPeriod, $toPeriod, $user_id)
{
	global $AppUI, $df, $durnTypes, $log_userfilter_users, $now, $priority, $system_users;
	global $z, $zi, $userAlloc, $task_assigned_users;

	$zi++;
	$users = isset($task_assigned_users[$task->task_id]) ? $task_assigned_users[$task->task_id] : $task->getAssignedUsers();
	$task->userPriority = $task->getUserSpecificTaskPriority($user_id);
	$projects = $task->getProject();

	ob_start();
	?>
	<tr>
		<td align="center" nowrap="nowrap">
			<input type="checkbox" name="selected_task[<?php echo $task->task_id; ?>]" value="<?php echo $task->task_id; ?>" />
		</td>
		<td align="center" nowrap="nowrap">
			<?php if ($task->userPriority) {
				$img = ($task->userPriority < 0) ? ('-' . -$task->userPriority) : ('+' . $task->userPriority);
			?>
			<img src="./images/icons/priority<?php echo $img; ?>.gif" width="13" height="16" alt="" />
			<?php } ?>
		</td>
		<td nowrap="nowrap">
			<?php for ($i = 0; $i < $level; $i++) { echo '&nbsp;&nbsp;&nbsp;'; } ?>
			<?php if ($task->task_milestone == true) { echo '<strong>'; } ?>
			<?php if ($level >= 1) { echo dPshowImage(dPfindImage('corner-dots.gif', 'tasks'), 16, 12, 'Subtask') . "&nbsp;"; } ?>
			<a href="?m=tasks&amp;a=view&amp;task_id=<?php echo $task->task_id; ?>"><?php echo dPformSafe($task->task_name); ?></a>
			<?php if ($task->task_milestone == true) { echo '</strong>'; } ?>
			<?php if ($task->task_priority) {
				$imgp = ($task->task_priority < 0) ? ('-' . -$task->task_priority) : ('+' . $task->task_priority);
			?>
			&nbsp;(<img src="./images/icons/priority<?php echo $imgp; ?>.gif" width="13" height="16" alt="" />)
			<?php } ?>
		</td>
		<td align="center">
			<a href="?m=projects&amp;a=view&amp;project_id=<?php echo $task->task_project; ?>" style="background-color:<?php echo @$projects['project_color_identifier']; ?>; color:<?php echo bestColor(@$projects['project_color_identifier']); ?>"><?php echo dPformSafe($projects['project_short_name']); ?></a>
		</td>
		<td align="center" nowrap="nowrap">
			<?php echo $task->task_duration . '&nbsp;' . $AppUI->_($durnTypes[$task->task_duration_type]); ?>
		</td>
		<td align="center" nowrap="nowrap">
			<?php $dt = new CDate($task->task_start_date); echo $dt->format($df); ?>&nbsp;&nbsp;&nbsp;
		</td>
		<td align="center" nowrap="nowrap">
			<?php $ed = new CDate($task->task_end_date); $diff = $now->dateDiff($ed); $sgn = $now->compare($ed, $now); echo ($diff * $sgn); ?>
		</td>
		<?php if ($display_week_hours) { echo displayWeeks($list, $task, $level, $fromPeriod, $toPeriod); } ?>
		<td>
			<?php $sep = ''; foreach ($users as $row) {
				if (!empty($row['user_id'])) {
					$uid = isset($row['user_id']) ? $row['user_id'] : '';
					$perc = isset($row['perc_assignment']) ? $row['perc_assignment'] : 0;
					echo $sep . '<a href="?m=admin&a=viewuser&user_id=' . $uid . '">' . dPformSafe($row['contact_first_name']) . ' ' . dPformSafe($row['contact_last_name']) . '&nbsp;(' . $perc . '%)</a>';
					$sep = ', ';
				}
			} ?>
		</td>
		<?php
		if ($zi == 1) {
			$zz = (($z < 5) ? 5 : ($z * 1.5));
			$zz = ((sizeof($users) >= 7) ? ($zz * 2) : $zz);
			$zm1 = $z - 2;
			$zm1 = (($zm1 <= 0) ? 1 : $zm1);
			$assUser = (isset($userAlloc[$user_id]) && isset($userAlloc[$user_id]['userFC'])) ? $userAlloc[$user_id]['userFC'] : '';
			$zm1 += (($user_id == 0) ? 1 : 0);
		?>
		<td valign="top" align="center" nowrap="nowrap" rowspan="<?php echo $zm1; ?>">
			<select name="add_users" style="width:200px" size="<?php echo ($zz - 1); ?>" class="text" multiple="multiple" ondblclick="javascript:chAssignment(<?php echo $user_id; ?>, 0, false)">
				<?php foreach ($userAlloc as $v => $u) { echo '<option value="' . $u['user_id'] . '">' . dPformSafe($u['userFC']) . '</option>'; } ?>
			</select>
		</td>
		<?php } ?>
	</tr>
	<?php
	return ob_get_clean();
}


function weekDates($fromPeriod, $toPeriod) {
	global $df;
	$row = '';
	
	// normalize range to week boundaries (Monday..Sunday)
	$sd = week_start_monday($fromPeriod);
	$ed = week_end_sunday($toPeriod);
	
	$row = "";
	while ($sd->before($ed)) {
		$row.= ("\t\t\t" . '<th title="' . $sd->getYear() . '" nowrap="nowrap">' 
		        . $sd->format($df) . "</th>\n");
		$sd->addSeconds(7 * 24 * 3600); //add one week
	}
	return $row;
}

function weekCells($display_week_hours, $fromPeriod, $toPeriod) {
	
	if (!($display_week_hours)) {
		return 0;
	}
	
	// normalize range to week boundaries (Monday..Sunday)
	$sd = week_start_monday($fromPeriod);
	$ed = week_end_sunday($toPeriod);
	
	$weeks = 0;
	while ($sd->before($ed)) {
		$weeks++;
		$sd->addSeconds(7 * 24 * 3600); //add one week
	}
	
	return $weeks;
}


// Look for a user when he/she has been allocated
// to this task and when. Report this in weeks
// This function is called within 'displayTask()'
function displayWeeks($list, $task, $level, $fromPeriod, $toPeriod) {
	
	// normalize range to week boundaries (Monday..Sunday)
	$sd = week_start_monday($fromPeriod);
	$ed = week_end_sunday($toPeriod);
	
	$st = new CDate($task->task_start_date);
	$et = new CDate($task->task_end_date);
	
	$row = '';
	while ($sd->before($ed)) {
		$sd_end = new CDate($sd->format('%Y-%m-%d 00:00:00'));
		$sd_end->addSeconds(7 * 24 * 3600); //add one week
		
		if (($sd->after($st) && $sd_end->before($et)) 
			|| ($st->before($sd_end) && !($st->before($sd))) 
			|| ($et->after($sd) && !($et->after($sd_end)))) {
			/*
			 * generate visually distiguishable colors for up to 12 task levels
			 * Color will just be blue (#0000FF) for levels 12th and up. 
			 */
			$red_key = (12 - (floor($level / 3) * 3));
			$red_key = (($red_key > 15) ? 15 : (($red_key < 0) ? 0 : $red_key));
			
			$green_key_1 = ($red_key + 4 - (($level % 3) * 4));
			$green_key_1 = (($green_key_1 > 15) ? 15 : (($green_key_1 < 0) ? 0 : $green_key_1));
			
			$green_key_2 = (($green_key_1 == $red_key) ? 0 : $green_key_1);
			
			$color_hex = mb_strtoupper('#' .dechex($red_key) . '0' . dechex($green_key_1) 
			                        . dechex($green_key_2) . 'FF');
			$row .= '<td nowrap="nowrap" style="background:' . $color_hex . ';" >';
		}
		else {
			$row .= '<td nowrap="nowrap">';
		}
		$row .= '&nbsp;</td>';
		$sd->addSeconds(7 * 24 * 3600); //add one week
	}
	return $row;
}

function getOrphanedTasks($tval) {
	global $task_assigned_users;
	$assigned = isset($task_assigned_users[$tval->task_id]) ? $task_assigned_users[$tval->task_id] : $tval->getAssignedUsers();
	return (sizeof($assigned) > 0) ? NULL : $tval;
}

?>
