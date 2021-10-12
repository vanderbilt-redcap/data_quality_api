# data_quality_api
Additional API to allow export and import of data quality information from REDCap

## Usage
To enable this extension to the REDCap API, module must be enabled on the Control Center of REDCap.

Additionally, this module must be enabled on each project before it can be used there.

Calls to this API are almost identical to the REDCap API, with the following changes.
1. URL must include the following GET parameters: prefix=[this_module_prefix], type=module, NOAUTH, page=[export|import], pid=[project_id]
2. [content] and [action] parameters are not required POST parameters, as they are specified by the prefix and page GET parameters

``` php
<?php

$data = array(
	'token' => '[token]',
	'format' => 'json',
	'returnFormat' => 'json',
	'records' => '[records]'
);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/api/?prefix=[prefix]&page=export&pid=[pid]&type=module&NOAUTH');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_VERBOSE, 0);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_AUTOREFERER, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
$output = curl_exec($ch);
curl_close($ch);
```

The above code snippet will pull all the data quality status/resolution information for the records and project IDs specified as long as the API token is valid for that project.

## Output
This is the output of the data quality export
``` json
{
	"[status_id]": {
		"status_id":"[status_id]",
		"rule_id":null,
		"pd_rule_id":null,
		"non_rule":"1",
		"project_id":"[pid]",
		"record":"[record]",
		"event_id":"[event_id]",
		"field_name":"[field_name]",
		"repeat_instrument":null,
		"instance":"[instance]",
		"status":null,
		"exclude":"0",
		"query_status":null,
		"assigned_username":null,
		"resolutions":{
			"[res_id_1]": {
				"res_id":"[res_id_1]",
				"status_id":"[status_id]",
				"ts":"[ts1]",
				"response_requested":"0",
				"response":null,
				"comment":"this is a test",
				"current_query_status":null,
				"upload_doc_id":null,
				"field_comment_edited":"0",
				"username":[username1]
			},
			"[res_id_2]": {
				"res_id":"[res_id_2]",
				"status_id":"[status_id]",
				"ts":"[ts2]",
				"response_requested":"0",
				"response":null,
				"comment":"this is a second test",
				"current_query_status":null,
				"upload_doc_id":null,
				"field_comment_edited":"0",
				"username":[username2]
			}
		}
	}
}
```


The data quality import only allows the import of new resolutions. A new status row will only be created if none exists for that project/event/record/instance/field combination. The API will also update the [query_status] for any status that has new resolutions with a new status being set.

Input for data quality import is identical to the data quality export's output. Duplicate resolutions will not be imported (those with the same ts and username as existing resolutions).

Output for data quality import is a json array of [res_ids].

## Syncing Data Resolutions between projects
When importing data resolutions using the index.php plugin, you will have the option to "Ignore PID and Usernames?"

This option should be used only in situations where you are syncing resolutions between projects that have the same data dictionaries and data, but differing users and PIDs.
