<?php
global $post;

$project_id = @$_POST["pid"];

if($project_id == "") die("Invalid request, pid missing");

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

# Add SQL to filter results by a single user
$userSql = "";
if($post['user'] != "") {
	$user_id = User::getUIIDByUsername($post['user']);
	if(!empty($user_id) && is_numeric($user_id)) {
		$userSql = " AND s.assigned_user_id = '" . db_escape($user_id) . "'";
	}
}

# Add SQL to filter results by a single status
$statusSql = "";
if($post['status'] != "") {
	if($post['status'] == "OPEN") {
		$statusSql = " AND (s.query_status = '" . db_escape($post['status']) . "' OR s.query_status IS NULL) ";
	} else {
		$statusSql = " AND s.query_status = '" . db_escape($post['status']) . "'";
	}
}

## Get list of status IDs associated with this project/record(s)
$sql1 = "
	SELECT s.*, g.value as group_id
	FROM redcap_data_quality_status s

	-- join to metadata to exclude fields that no longer exist (like REDCap does programmatically)
	JOIN redcap_metadata m
		ON m.project_id = $project_id
		AND s.field_name = m.field_name

	-- this joins one row per event & instance, requiring the GROUP BY below
	LEFT JOIN redcap_data g
		ON s.record = g.record
		AND g.project_id = $project_id
		AND g.field_name = '__GROUPID__'

	WHERE s.project_id = ".$project_id.$recordSql.$userSql.$statusSql."
	GROUP BY status_id
";

$q = db_query($sql1);

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

// $uid = User::getUIIDByUsername($post['user']);

// $retData = array(
// 	'uid' => $uid,
// 	'user' => $post['user'],
// 	'sql' => $sql
// );

// $content = json_encode($sql1);
$content = json_encode($statusList);

# Export to a JSON file
$datestamp = new DateTime();
$filename=$project_id."-data_quality-".$datestamp->format("Y-m-d").".json";

// $fp = fopen($filename, 'w');
// fwrite($fp, $content);
// fclose($fp);

header("Content-type: application/json");
header("Content-Disposition: attachment; filename=".$filename);
header("Pragma: no-cache");
header("Expires: 0");

echo($content);
