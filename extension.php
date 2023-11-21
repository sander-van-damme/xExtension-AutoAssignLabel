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
		$entries = iterator_to_array($entryDao->selectAll());
		$unreadEntries = array_filter($entries, function ($entry) {
			if (isset($entry['is_read']) && $entry['is_read'] === true) {
				return false;
			}
			return true;
		});

		# Add labels to unread entries.
		$unreadEntriesWithLabels = $this->getEntriesWithLabels($unreadEntries);
		foreach ($unreadEntriesWithLabels as $entry) {
			$entryId = $entry["id"];
			$this->unassignEntryTags($entryId);
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

	private function unassignEntryTags($entryId)
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
		$requestBody = array_map(function ($entry) {
			return [
				'id' => $entry['id'],
				'title' => $entry['title'],
				'content' => $entry['content'],
			];
		}, $entries);
		$requestBodyJson = json_encode($requestBody);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $requestBodyJson);

		// Get response.
		$response = json_decode(curl_exec($curl));
		curl_close($curl);

		return $response;
	}
}
