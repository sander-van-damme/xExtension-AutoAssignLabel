<?php
class AutoAssignLabelExtension extends Minz_Extension
{
	public function init()
	{
		$this->registerHook('freshrss_user_maintenance', array($this, 'assignLabels'));
	}

	public function handleConfigureAction()
	{
		if (Minz_Request::isPost()) {
			FreshRSS_Context::$user_conf->auto_assign_label_api_url = Minz_Request::param('auto_assign_label_api_url', '');
			FreshRSS_Context::$user_conf->auto_assign_label_api_key = Minz_Request::param('auto_assign_label_api_key', '');
			FreshRSS_Context::$user_conf->save();
		}
	}

	public function assignLabels()
	{
		$entryDao = FreshRSS_Factory::createEntryDao();
		$tagDao = FreshRSS_Factory::createTagDao();

		# Get all unread entries.
		$entries = array_filter($entryDao->selectAll(), function ($entry) {
			return $entry["is_read"] == false;
		});

		# Add labels to entries.
		$entriesWithLabels = $this->getEntriesWithLabels($entries);
		foreach ($entriesWithLabels as $entry) {
			$entryId = $entry["id"];
			$this->deleteEntryTags($entryId);
			$tagId = $this->getTagId($entry["label"]);
			$tagDao->tagEntry($tagId, $entryId, true);
		}
	}


	private function getTagId($tagName)
	{
		$tagDao = FreshRSS_Factory::createTagDao();
		$tagId = null;
		// If tag exists.
		if ($tag = $tagDao->searchByName($tagName)) {
			$tagId = $tag->id();
		}
		// If tag does not exist.
		else {
			$tagId = $tagDao->addTag(['name' => $tagName]);
		}
		return $tagId;
	}

	private function deleteEntryTags($entryId)
	{
		$tagDao = FreshRSS_Factory::createTagDao();
		$tags = $tagDao->getTagsForEntry($entryId);
		foreach ($tags as $tag) {
			$tagId = $tag["id"];
			$tagDao->tagEntry($tagId, $entryId, false);
		}
	}

	private function getEntriesWithLabels($entries)
	{
		try {
			// Define external API.
			$apiUrl = FreshRSS_Context::$user_conf->auto_assign_label_api_url;
			$apiKey = FreshRSS_Context::$user_conf->auto_assign_label_api_key;

			// Create request.
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_FAILONERROR, true);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_TIMEOUT, 300);

			// Set request URL.
			curl_setopt($curl, CURLOPT_URL, $apiUrl);

			// Set request method.
			curl_setopt($curl, CURLOPT_POST, true);

			// Set request headers.
			$requestHeaders = array(
				"Accept: application/json",
				"Content-Type: application/json",
				"X-Open-Ai-Api-Key: {$apiKey}"
			);
			curl_setopt($curl, CURLOPT_HTTPHEADER, $requestHeaders);

			// Set request body.
			$requestBodyJson = json_encode($entries);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $requestBodyJson);

			// Get response.
			$response = json_decode(curl_exec($curl));
			return $response;
		} catch (Exception $e) {
			return 'unknown';
		} finally {
			curl_close($curl);
		}
	}
}
