<?php
$modulePid = $project_id;

global $format, $returnFormat, $post;

$module->checkApiToken();

## Trying to run module using wrong pid, could allow access of module on non-enabled projects
if($post["projectid"] != $modulePid || $post["projectid"] == "") {
	die();
}

// Get user's user rights
$user_rights = UserRights::getPrivileges(PROJECT_ID, USERID);
$user_rights = $user_rights[PROJECT_ID][strtolower(USERID)];
$ur = new UserRights();
$ur->setFormLevelPrivileges();

$Proj = new Project(PROJECT_ID);

// Prevent data imports for projects in inactive or archived status
if ($Proj->project['status'] > 1) {
	if ($Proj->project['status'] == '2') {
		$statusLabel = "Inactive";
	} elseif ($Proj->project['status'] == '3') {
		$statusLabel = "Archived";
	} else {
		$statusLabel = "[unknown]";
	}
	die(RestUtility::sendResponse(403, "Data may not be imported because the project is in $statusLabel status."));
}

$insertValues = [];
$resolutionInsertIds = [];
$userIdConversion = array(NULL => NULL);

$projectSettings = $Proj->project;
$dataQualityProcess = false;
$dataCommentProcess = false;
$editComments = false;

## Check if project has data resolution workflow turned on and ability to delete data resolution comments
if($projectSettings['data_resolution_enabled'] == 2) {
	$dataQualityProcess = true;
}
else if($projectSettings['data_resolution_enabled'] == 1) {
	$dataCommentProcess = true;
}
else {
	die(RestUtility::sendResponse(403, "Data may not be imported because the project does not have data quality enabled."));
}

if($projectSettings['field_comment_edit_delete'] == 1) {
	$editComments = true;
}

$single_event = false;
if(sizeof($Proj->events)==1 && $projectSettings['repeatforms']==0){
	$single_event = true;
}

## TODO Convert flat/csv data into nested array format
$importData = json_decode($post['data'],true);

foreach($importData as $dataRow) {
	$statusId = $dataRow['status_id'];
	$record = $dataRow['record'];
	$projectId = $dataRow['project_id'];
	$eventId = $dataRow['event_id'];
	$fieldName = $dataRow['field_name'];
	$instance = $dataRow['instance'];
	$repeatInstrument = $dataRow['repeat_instrument'];
	$assignedUsername = $dataRow['assigned_username'];
	$newStatusId = false;

	## Verify field name and event ID exist on this project. Skip this row if it doesn't
	if(!array_key_exists($fieldName,$Proj->metadata)) {
		continue;
	}

	$eventFound = false;
	foreach($Proj->events as $armDetails) {
		foreach($armDetails["events"] as $tempEventId => $eventDetails) {
			// check for non-longitudinal project
			if (count($armDetails['events']) == 1 && $single_event){
				$eventId = $tempEventId;
				$eventFound = true;
				break 2;
			}else if($eventId == $tempEventId) {
				$eventFound = true;
				break 2;
			}
		}
	}

	if(!$eventFound) {
		continue;
	}

	## Skip if record/project/event/field is blank
	if($record == "" || $projectId == "" || $eventId == "" || $fieldName == "") continue;

	## Skip data where the projectId doesn't match the token's projectId
	if($projectId != PROJECT_ID) continue;


	## Confirm if this status is already in the database
	$sql = "SELECT s.status_id, s.instance, s.repeat_instrument, s.record, s.project_id, s.event_id, s.field_name
			FROM redcap_data_quality_status s
			WHERE ".(($statusId == "" || !is_numeric($statusId)) ? "" : "s.status_id = '".db_escape($statusId)."'")."
				OR (s.record = '".db_escape($record)."'
					AND s.project_id = '".db_escape($projectId)."'
					AND s.event_id = '".db_escape($eventId)."'
					AND s.field_name = '".db_escape($fieldName)."'".
					($repeatInstrument != NULL ? " AND s.repeat_instrument = '".db_escape($repeatInstrument)."'" :
					($instance == NULL ? " AND s.instance is NULL" : " AND s.instance = '".db_escape($instance)."'")).")";

	$q = db_query($sql);

	## Cache User ID to username conversion to reduce DB calls
	if(!array_key_exists($assignedUsername,$userIdConversion)) {
		$userIdConversion[$assignedUsername] = User::getUIIDByUsername($assignedUsername);
	}

	$existingStatus = "";
	while($row = db_fetch_assoc($q)) {
		## Found a matching record/project/event/field/instance with a different status
		if($row['status_id'] != $statusId) {
			$existingStatus = $row['status_id'];
			$newStatusId = true;
		}
		## Found a row for that statusId, verify if record/project/event/field/instance matches
		else if($row['record'] == $record && $row['project_id'] == $projectId
				&& $row['event_id'] == $eventId && $row['field_name'] == $fieldName){
			if($instance == "" || $row['instance'] == $instance) {
				$existingStatus = $row['status_id'];
				$newStatusId = false;
			}
		}
	}

	## Add new status row
	## Need to verify project ID and event ID are integers
	if($existingStatus == ""
		&& (is_int($projectId) || ctype_digit($projectId))
		&& (is_int($eventId) || ctype_digit($eventId))) {
		$sql = "INSERT INTO redcap_data_quality_status
				(non_rule,project_id,record,event_id,field_name,instance,assigned_user_id)
				VALUES (1,".db_escape($projectId).",'".db_escape($record)."',".db_escape($eventId).",'".
			db_escape($fieldName)."',".checkNull($instance).",".checkNull($userIdConversion[$assignedUsername]).")";
	
	db_query($sql);
		$existingStatus = db_insert_id();
		$newStatusId = true;
	}

	## Do date conversion on resolutions so they can be quickly sorted
	foreach($dataRow['resolutions'] as $resKey => $resolutionRow) {
		$dataRow['resolutions'][$resKey]['tsInSeconds'] = strtotime($resolutionRow['ts']);
	}

	## Sort resolutions by timestamp and get last timestamp in DB
	usort($dataRow['resolutions'],function($a,$b) {
		if($a['tsInSeconds'] > $b['tsInSeconds']) {
			return 1;
		}
		else if($a['tsInSeconds'] == $b['tsInSeconds']) {
			return 0;
		}
		return -1;
	});

	$sql = "SELECT r.ts,r.user_id
			FROM redcap_data_quality_resolutions r
			WHERE r.status_id = '".$existingStatus."'
			ORDER BY r.ts DESC";

	$q = db_query($sql);
	$existingResolutions = array();
	while($row = db_fetch_assoc($q)) {
		$existingResolutions[$row['ts'].$row['user_id']] = 1;
	}

	$dbTs = db_result($q,0,"ts");
	$lastTs = "";

	foreach($dataRow['resolutions'] as $resolutionRow) {
		if($editComments) {
			# TODO Allow update of data resolution status/comments
			//DataQuality::editFieldComment();
		}

		// Determine the status to set
		if (in_array($resolutionRow['current_query_status'], array('OPEN','CLOSED','VERIFIED','DEVERIFIED'))) {
			$resStatus = $resolutionRow['current_query_status'];
		} elseif ((isset($resolutionRow['response_requested']) && $resolutionRow['response_requested'])
				|| ($resolutionRow['response'] && $resolutionRow['response'])) {
			$resStatus = 'OPEN';
		} else {
			$resStatus = '';
		}

		// Make sure response is in enum list
		if(in_array($resolutionRow['response'],array('DATA_MISSING','TYPOGRAPHICAL_ERROR','CONFIRMED_CORRECT','WRONG_SOURCE','OTHER'))) {
			$response = $resolutionRow['response'];
		}
		else {
			$response = '';
		}

		$resolutionId = $resolutionRow['res_id'];
		$resStatusId = $resolutionRow['status_id'];
		$username = $resolutionRow['username'];

		## Skip resolution rows that don't have matching status IDs unless this is a new status
		if(!$newStatusId && $resStatusId != "" && $resStatusId != $existingStatus) continue;

		## Cache User ID to username conversion to reduce DB calls
		if(!array_key_exists($username,$userIdConversion)) {
			$userIdConversion[$username] = User::getUIIDByUsername($username);
		}

		## Skip resolution rows that already have a resolution from the same user for the same timestamp
		## This is to prevent duplicate response rows
		if(array_key_exists($resolutionRow['ts'].$userIdConversion[$username],$existingResolutions)) {
			continue;
		}

		$lastTs = $resolutionRow['ts'];

		$insertValues[] = "(".checkNull($existingStatus).",".checkNull($resolutionRow['ts']).",".
				checkNull($userIdConversion[$username]).",".(in_array($resolutionRow['response_requested'],[0,1]) ? $resolutionRow['response_requested'] : 0).",".
				checkNull($response).",".checkNull($resolutionRow['comment']).",".
				checkNull($resStatus).")";

		$existingResolutions[$resolutionRow['ts'].$userIdConversion[$username]] = 1;

		## Do inserts in groups of 10 to reduce DB calls
		if(count($insertValues) >= 10) {
			$sql = "INSERT INTO redcap_data_quality_resolutions
					(status_id,ts,user_id,response_requested,response,comment,current_query_status)
					VALUES ".implode(",",$insertValues);
			db_query($sql);

			if($e = db_error()) {
				error_log("Data Quality Import: ".$e);
			}
			else {
				$nextId = db_insert_id();
				for($i = 0; $i < 10; $i++) {
					$resolutionInsertIds[] = $nextId + $i;
				}
			}
			$insertValues = [];
		}
	}

	## If last timestamp is after last timestamp in DB, then update main query_status
	if($existingStatus && $lastTs != "" && strtotime($lastTs) > strtotime($dbTs)) {
		$sql = "UPDATE redcap_data_quality_status
				SET query_status = ".checkNull($resStatus)."
				WHERE status_id = ".$existingStatus;

		db_query($sql);
	}
}

if(count($insertValues) > 0) {
	## Do last inserts pending in $insertValues
	$sql = "INSERT INTO redcap_data_quality_resolutions
			(status_id,ts,user_id,response_requested,response,comment,current_query_status)
			VALUES ".implode(",",$insertValues);
	db_query($sql);

	if($e = db_error()) {
		error_log("Data Quality Import: ".$e);
	}
	else {
		$nextId = db_insert_id();
		for($i = 0; $i < count($insertValues); $i++) {
			$resolutionInsertIds[] = $nextId + $i;
		}
	}
	$insertValues = [];
}

## TODO Send other types of responses based on request
$content = json_encode($resolutionInsertIds);

# Send the response to the requestor
RestUtility::sendResponse(200, $content, $format);

function csv($data,$headers) {
	foreach($data as $dataRow) {
		foreach($headers as $column => $label) {
			if($label == "record") {
				## If record is blank, this must be a data resolution row
				if($dataRow[$column] == "") {

				}
				## Else, this must be a status row
				else {

				}
			}
		}
	}
}
