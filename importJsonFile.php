<?php
global $format, $returnFormat, $post;
$ignore = $_POST['ignore'];
$file_repo = $_POST['file_repo'];

// Get user's user rights
$user_rights = UserRights::getPrivileges(PROJECT_ID, USERID);
$user_rights = $user_rights[PROJECT_ID][strtolower(USERID)];
$ur = new UserRights();
$ur->setFormLevelPrivileges();

$Proj = new Project(PROJECT_ID);
$errors = array();
$notes = array();

// Prevent data imports for projects in inactive or archived status
if ($Proj->project['status'] > 1) {
	if ($Proj->project['status'] == '2') {
		$statusLabel = "Inactive";
	} elseif ($Proj->project['status'] == '3') {
		$statusLabel = "Archived";
	} else {
		$statusLabel = "[unknown]";
	}
	array_push($errors, "Data may not be imported because the project is in $statusLabel status.");
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
	array_push($errors, "Data may not be imported because the project does not have data quality enabled.");
}

if($projectSettings['field_comment_edit_delete'] == 1) {
	$editComments = true;
}

$single_event = false;
if(sizeof($Proj->events)==1 && $projectSettings['repeatforms']==0){
	$single_event = true;
}

// Import the json file
if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
	$importData = json_decode(file_get_contents($_FILES['import_file']['tmp_name']),true);
}else{
	array_push($errors, "File failed to upload");
}

if(is_null($importData)){
	array_push($errors, "Failed to decode json. Please ensure that the file selected was json...");
	$content = NULL;
}else{
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
			array_push($notes, "Event not found.");
			continue;
		}

		## Skip if record/project/event/field is blank
		if($record == "" || $projectId == "" || $eventId == "" || $fieldName == "") continue;

		## Skip data where the projectId doesn't match the token's projectId
		if($projectId != PROJECT_ID && is_null($ignore)){
			continue;
		}else{
			## Allow imports from other projects--bypassing project ID
			$ignored_project_id = $projectId;
			$projectId = PROJECT_ID;
		}

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
			if(User::getUIIDByUsername($assignedUsername) || is_null($ignore)){
				$userIdConversion[$assignedUsername] = User::getUIIDByUsername($assignedUsername);
			}else{
				// Ignore the user id imported and instead overwrite with current user
				$ignored_username = $username;
				$userIdConversion[$assignedUsername] = User::getUIIDByUsername(USERID);
			}
		}

		$existingStatus = "";
		while($row = db_fetch_assoc($q)) {
			## Found a matching record/project/event/field/instance with a different status
			if($row['status_id'] != $statusId) {
				array_push($notes, "Found a matching record/project/event/field/instance with a different status");
				$existingStatus = $row['status_id'];
				$newStatusId = true;
			}
			## Found a row for that statusId, verify if record/project/event/field/instance matches
			else if($row['record'] == $record && ($row['project_id'] == $projectId || !is_null($ignore))
					&& $row['event_id'] == $eventId && $row['field_name'] == $fieldName){
				if($instance == "" || $row['instance'] == $instance) {
					array_push($notes, "Found a row for this statusId, verified that record/project/event/field/instance matches");
					$existingStatus = $row['status_id'];
					$newStatusId = false;
				}
			}
		}

		## Add new status row
		if($existingStatus == "" || is_null($existingStatus)) {
			array_push($errors,$record_esc);
			$sql = "INSERT INTO redcap_data_quality_status
					(non_rule,project_id,record,event_id,field_name,instance,assigned_user_id)
					VALUES (1,".db_escape($projectId).",'".db_escape($record)."',".db_escape($eventId).",'".
					db_escape($fieldName)."',".checkNull($instance).",".checkNull($userIdConversion[$assignedUsername]).")";
			$q = db_query($sql);
			if($e = db_error()) {
				array_push($errors,"sql: ".$sql);
				array_push($errors,"Insert Status error: ".$e);
			}
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
			array_push($notes,"Old res_id=".$resolutionId."; res_status_id=".$resStatusId);

			## Skip resolution rows that don't have matching status IDs unless this is a new status
			if(!$newStatusId && $resStatusId != "" && $resStatusId != $existingStatus){
				array_push($notes, "Skip resolution rows that don't have matching status IDs unless this is a new status");
				continue;
			}

			## Cache User ID to username conversion to reduce DB calls
			if(!array_key_exists($username,$userIdConversion)) {
				if(User::getUIIDByUsername($assignedUsername) || is_null($ignore)){
					$userIdConversion[$assignedUsername] = User::getUIIDByUsername($assignedUsername);
				}else{
					// Ignore the user id imported and instead overwrite with current user
					$ignored_username = $username;
					$userIdConversion[$assignedUsername] = User::getUIIDByUsername(USERID);
				}
			}

			## Skip resolution rows that already have a resolution from the same user for the same timestamp
			## This is to prevent duplicate response rows
			if(array_key_exists($resolutionRow['ts'].$userIdConversion[$username],$existingResolutions)) {
				array_push($notes,"Skipping resolution row--already has a resolution from the same user for the same timestamp");
				continue;
			}

			$lastTs = $resolutionRow['ts'];

			# append the userid to the comments if it will be ignored
			$append_comment = $resolutionRow['comment'];
			if(!is_null($ignore)){
				$add_info =	" (DRW Import PID:".$ignored_project_id.", ";
				$add_info .=	"RESID:".$resolutionId.", ";
				$add_info .=	"UID:".$ignored_username.")";
				array_push($notes, $add_info);
			}
			$append_comment .= $add_info;

			$insertValues[] = "(".checkNull($existingStatus).",".checkNull($resolutionRow['ts']).",".
					checkNull($userIdConversion[$username]).",".(in_array($resolutionRow['response_requested'],[0,1]) ? $resolutionRow['response_requested'] : 0).",".
					checkNull($response).",".checkNull($append_comment).",".
					checkNull($resStatus).")";

			$existingResolutions[$resolutionRow['ts'].$userIdConversion[$username]] = 1;

			## Do inserts in groups of 10 to reduce DB calls
			if(count($insertValues) >= 10) {
				array_push($notes, "Inserting resolutions in batches of 10.");
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
		array_push($notes, "________________________");
	} # end foreach json row

	if(count($insertValues) > 0) {
		## Do last inserts pending in $insertValues
		array_push($notes, "Inserting final set of resolutions.");
		$sql = "INSERT INTO redcap_data_quality_resolutions
				(status_id,ts,user_id,response_requested,response,comment,current_query_status)
				VALUES ".implode(",",$insertValues);
		db_query($sql);

		if($e = db_error()) {
			error_log("Data Quality Import: ".$e);
			array_push($errors,$e);
		}
		else {
			$nextId = db_insert_id();
			for($i = 0; $i < count($insertValues); $i++) {
				$resolutionInsertIds[] = $nextId + $i;
			}
		}
		$insertValues = [];
	}

	$content = json_encode($resolutionInsertIds);

	if(!is_null($file_repo)){
		# Save the file to the repository
		array_push($notes,"Attempting to save to file repository...");
		$info_to_save = "MODIFIED CONTENT = ".implode(";",$content)."\n\n"."NOTES = ".implode(";",$notes)."\n\n"."ERRORS = ".implode(";",$errors)."\n\n"."JSON = ".json_encode($importData);
		$saved = saveToFileRepository("DRW Log", $info_to_save, "txt");
	}
}


if (!empty($errors))
{
	require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
	print "<div class='red' style='margin:20px 0;'>";
	foreach($errors as $error){
		print "<p>$error</p>";
	}
	print "</div>";
	print "<div class='green' style='margin:20px 0;'>";
	print "<p>RES IDS MODIFIED:$content</p>";
	print "<p>-------------LOG:-------------</p>";
	foreach($notes as $note){
		print "<p>";
		print_r($note);
		print "</p>";
	}
	print "</div>";
	require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
}
else
{
	require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
	print "<div class='green' style='margin:20px 0;'>";
	if(strlen($content) >2){
		print "<p>SUCCESS! RES IDS MODIFIED:$content</p>";
		print "<p>-------------LOG:-------------</p>";
		foreach($notes as $note){
			print "<p>";
			print_r($note);
			print "</p>";
		}
		print "</div>";
		require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
	}else{
		print "<p>Process completed, but no new resolutions were added. Is that what you expected?</p>";
	}
	if (!empty($saved)){
		print "<div class='yellow'>NOTE FOR FILE SAVE: ";
		foreach($saved as $save){
			print $save;
		}
		print "</div>";
	}
}

/**
 * Saves a file to REDCap's File Repository. Based off stolen code from BCCHR-IT/custom-template-engine
 * https://github.com/BCCHR-IT/custom-template-engine
 *
 * @param String $filename         Name of file
 * @param String $file_contents    Contents of file
 * @param String $file_extension   File extension
 * @see deleteRepositoryFile() For deleting a file from the repository, if metadata failed to create.
 */
function saveToFileRepository($filename, $file_contents, $file_extension)
{
    // Upload the compiled report to the File Repository
    $notes = array();
    $database_success = FALSE;
    $upload_success = FALSE;

    $dummy_file_name = $filename;
    $dummy_file_name = preg_replace("/[^a-zA-Z-._0-9]/","_",$dummy_file_name);
    $dummy_file_name = str_replace("__","_",$dummy_file_name);
    $dummy_file_name = str_replace("__","_",$dummy_file_name);
		$pid = PROJECT_ID;
		$uid = USERID;

    $stored_name = date('YmdHis') . "_pid" . $pid . "_" . generateRandomHash(6) . ".$file_extension";

    $upload_success = file_put_contents(EDOC_PATH . $stored_name, $file_contents);

    if ($upload_success !== FALSE)
    {
        $dummy_file_size = $upload_success;
        $dummy_file_type = "application/$file_extension";

        $file_repo_name = date("Y/m/d H:i:s");

        $sql = "INSERT INTO redcap_docs (project_id,docs_date,docs_name,docs_size,docs_type,docs_comment,docs_rights)
                VALUES ($pid,CURRENT_DATE,'$dummy_file_name.$file_extension','$dummy_file_size','$dummy_file_type',
                \"$file_repo_name - $filename ($uid)\",NULL)";

        if (db_query($sql))
        {
            $docs_id = db_insert_id();

            $sql = "INSERT INTO redcap_edocs_metadata (stored_name,mime_type,doc_name,doc_size,file_extension,project_id,stored_date)
                    VALUES('".$stored_name."','".$dummy_file_type."','".$dummy_file_name."','".$dummy_file_size."',
                    '".$file_extension."','".$pid."','".date('Y-m-d H:i:s')."');";

            if (db_query($sql))
            {
                $doc_id = db_insert_id();
                $sql = "INSERT INTO redcap_docs_to_edocs (docs_id,doc_id) VALUES ('".$docs_id."','".$doc_id."');";

                if (db_query($sql))
                {
                    if ($project_language == 'English')
                    {
                        // ENGLISH
                        $context_msg_insert = "{$lang['docs_22']} {$lang['docs_08']}";
                    }
                    else
                    {
                        // NON-ENGLISH
                        $context_msg_insert = ucfirst($lang['docs_22'])." {$lang['docs_08']}";
                    }

                    // Logging
                    REDCap::logEvent("Data Quality API - Uploaded document to file repository", "Successfully uploaded $filename");
										array_push($notes,"Uploaded document to file repository");
                    $context_msg = str_replace('{fetched}', '', $context_msg_insert);
                    $database_success = TRUE;
                }
                else
                {
                    /* if this failed, we need to roll back redcap_edocs_metadata and redcap_docs */
                    db_query("DELETE FROM redcap_edocs_metadata WHERE doc_id='".$doc_id."';");
                    db_query("DELETE FROM redcap_docs WHERE docs_id='".$docs_id."';");
                    deleteRepositoryFile($stored_name);
										array_push($notes,"Upload failed: CODE1");
                }
            }
            else
            {
                /* if we failed here, we need to roll back redcap_docs */
                db_query("DELETE FROM redcap_docs WHERE docs_id='".$docs_id."';");
                deleteRepositoryFile($stored_name);
								array_push($notes,"Upload failed: CODE2");
            }
        }
        else
        {
            /* if we failed here, we need to delete the file */
            deleteRepositoryFile($stored_name);
						array_push($notes,"Upload failed: CODE3");
						array_push($notes, $sql);
        }
    }else{
			array_push($notes,"Upload failed: CODE4");
		}

    if ($database_success === FALSE)
    {
        $context_msg = "<b>{$lang['global_01']}{$lang['colon']} {$lang['docs_47']}</b><br>" . $lang['docs_65'] . ' ' . maxUploadSizeFileRespository().'MB'.$lang['period'];

        if ($super_user)
        {
            $context_msg .= '<br><br>' . $lang['system_config_69'];
        }
    }
		array_push($notes, $context_msg);
    return $notes;
}


/**
 * Helper function that deletes a file from the File Repository, if REDCap data about it fails
 * to be inserted to the database.Stolen code from redcap version/FileRepository/index.php.
 *
 * @param String $file     Name of file to delete
 * @since 1.0
 * @access private
 */
function deleteRepositoryFile($file)
{
		global $edoc_storage_option,$wdc,$webdav_path;
		if ($edoc_storage_option == '1') {
				// Webdav
				$wdc->delete($webdav_path . $file);
		} elseif ($edoc_storage_option == '2') {
				// S3
				global $amazon_s3_key, $amazon_s3_secret, $amazon_s3_bucket;
				$s3 = new S3($amazon_s3_key, $amazon_s3_secret, SSL); if (isset($GLOBALS['amazon_s3_endpoint']) && $GLOBALS['amazon_s3_endpoint'] != '') $s3->setEndpoint($GLOBALS['amazon_s3_endpoint']);
				$s3->deleteObject($amazon_s3_bucket, $file);
		} else {
				// Local
				@unlink(EDOC_PATH . $file);
		}
}
