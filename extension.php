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

		// Get all entries.
		$entries = array_values(iterator_to_array($entryDao->selectAll()));
		if (empty($entries)) {
			Minz_Log::info("Auto Assign Label Extension: No entries found.");
			return;
		}

		// Get all unread entries.
		$unreadEntries = array_values(array_filter($entries, function ($entry) {
			if (isset($entry['is_read']) && $entry['is_read']) {
				return false;
			}
			return true;
		}));
		if (empty($unreadEntries)) {
			Minz_Log::info("Auto Assign Label Extension: No unread entries found.");
			return;
		}

		// Get labels for unread entries.
		$unreadEntriesWithLabels = $this->getEntriesWithLabels($unreadEntries);
		if (empty($unreadEntriesWithLabels)) {
			Minz_Log::info("Auto Assign Label Extension: No labels found.");
			return;
		}

		// Assign labels to unread entries.
		foreach ($unreadEntriesWithLabels as $entry) {
			if (isset($entry["label"])) {
				$entryId = $entry["id"];
				$this->unassignEntryTags($entryId);
				$tagId = $this->getTagId($entry["label"]);
				$tagDao->tagEntry($tagId, $entryId, true);
			}
		}
	}

	private function getTagId($tagName)
	{
		$tagDao = FreshRSS_Factory::createTagDao();

		// Modify tag name to avoid search errors.
		// This is due to implicit string modification by FreshRSS when adding tags.
		$tagName = trim(mb_strcut(trim($tagName), 0, FreshRSS_DatabaseDAO::LENGTH_INDEX_UNICODE, 'UTF-8'));
		$tagName = trim(mb_strcut($tagName, 0, 60, "UTF-8"));

		// If tag exists.
		if ($tag = $tagDao->searchByName($tagName)) {
			return $tag->id();
		}
		// If tag does not exist.
		return $tagDao->addTag(['name' => $tagName]);
	}

	private function unassignEntryTags($entryId)
	{
		$tagDao = FreshRSS_Factory::createTagDao();
		$tags = $tagDao->getTagsForEntry($entryId);
		if (!empty($tags)) {
			foreach ($tags as $tag) {
				$tagDao->tagEntry($tag["id"], $entryId, false);
			}
		}
	}

	private function getEntriesWithLabels($entries)
	{
		try {
			// Assert that entries is not null.
			if (empty($entries)) {
				throw new Exception("Entries must not be null.");
			}

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
			$requestBody = array_values(array_map(function ($entry) {
				return [
					'id' => $entry['id'],
					'title' => substr($entry['title'], 0, 1000),
					'content' => substr($entry['content'], 0, 2000),
				];
			}, $entries));
			$requestBodyJson = json_encode($requestBody);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $requestBodyJson);

			// Get response.
			$curl_response = curl_exec($curl);

			// Handle curl errors.
			if ($curl_response === false) {
				$error_msg = curl_error($curl);
				throw new Exception($error_msg);
			}

			// Decode response.
			$response = json_decode($curl_response, true);

			// Assert that decoded response is not null.
			if (is_null($response)) {
				throw new Exception("Response must not be null.");
			}

			return $response;
		} catch (Exception $e) {
			Minz_Log::error("Auto Assign Label Extension internal error: " . $e->getMessage());
			return [];
		} finally {
			curl_close($curl);
		}
	}
}
