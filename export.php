<?php
$module->checkApiToken();

global $post;

$project_id = db_escape($post['projectid']);

if($project_id == "") die();

# Add SQL to filter results by a single record
$recordSql = "";
if($post['record'] != "") {
	if(is_array($post['record'])) {
		## Clean API variables individually
		$recordList = [];
		foreach($post['record'] as  $newRecord) {
			$recordList[] = db_escape($newRecord);
		}
		$recordSql = " AND s.record IN ('".implode("','",$recordList)."')";
	}
	else {
		$recordSql = " AND s.record = '".db_escape($post['record'])."'";
	}
}

## Get list of status IDs associated with this project/record(s)
$sql = "SELECT s.*
		FROM redcap_data_quality_status s
		WHERE s.project_id = ".$project_id.$recordSql;

$q = db_query($sql);

if($e = db_error()) {
	throw new Exception("Database error while pulling data quality status");
}

$statusList = array();
$userIdConversion = array(NULL => NULL);

## Read all the rows from the table ino the $statusList variable
while($row = db_fetch_assoc($q)) {
	$userId = $row['assigned_user_id'];
	unset($row['assigned_user_id']);

	## Cache User ID to username conversion to reduce DB calls
	if(!array_key_exists($userId,$userIdConversion)) {
		$userIdConversion[$userId] = User::getUserInfoByUiid($userId)['username'];
	}
	$row['assigned_username'] = $userIdConversion[$userId];
	$statusList[$row['status_id']] = $row;
}

if(count($statusList) > 0) {
	$sql = "SELECT r.*
			FROM redcap_data_quality_resolutions r
			WHERE r.status_id IN ('".implode("','",array_keys($statusList))."')";

	$q = db_query($sql);

	if($e = db_error()) {
		throw new Exception("Database error while pulling data quality resolutions");
	}

	while($row = db_fetch_assoc($q)) {
		if(!array_key_exists("resolutions",$statusList[$row['status_id']])) {
			$statusList[$row['status_id']]["resolutions"] = array();
		}
		$userId = $row['user_id'];
		unset($row['user_id']);

		## Cache User ID to username conversion to reduce DB calls
		if(!array_key_exists($userId,$userIdConversion)) {
			$userIdConversion[$userId] = User::getUserInfoByUiid($userId)['username'];
		}
		$row['username'] = $userIdConversion[$userId];
		$statusList[$row['status_id']]["resolutions"][$row["res_id"]] = $row;
	}
}

$content = json_encode($statusList);

# Send the response to the requestor
RestUtility::sendResponse(200, $content, $format);