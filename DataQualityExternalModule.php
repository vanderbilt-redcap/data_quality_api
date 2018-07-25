<?php
namespace Vanderbilt\DataQualityExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class DataQualityExternalModule extends AbstractExternalModule
{
	public function checkApiToken() {

		global $post;

		/** @var \RestRequest $data */
		$data = \RestUtility::processRequest(true);

		$post = $data->getRequestVars();
	}
}