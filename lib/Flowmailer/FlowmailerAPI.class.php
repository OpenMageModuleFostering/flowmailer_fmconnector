<?php
	namespace flowmailer;

	class Attachment {
		public $content;
		public $contentId;
		public $contenType;
		public $filename;
	}
	
	class Header {
		public $name;
		public $value;
	}

	class SubmitMessage {
		public $attachments;
		public $data;
		public $deliveryNotificationType;
		public $headerFromAddress;
		public $headerFromName;
		public $headerToAddress;
		public $headerToName;
		public $headers;
		public $html;
		public $messageType;
		public $mimedata;
		public $recipientAddress;
		public $senderAddress;
		public $subject;
		public $text;
	}

	class FlowmailerAPI {
		private $authURL = 'https://login.flowmailer.net/oauth/token';
		private $baseURL = 'http://api.flowmailer.net';
		private $apiVersion = '1.4';

		private $maxAttempts = 3;

		private $authToken;
		private $authTime;

		function __construct($accountId, $clientId, $clientSecret) {
			$this->accountId = $accountId;
			$this->clientId = $clientId;
			$this->clientSecret = $clientSecret;
			
			$this->curlMulti = curl_multi_init();
		}
		
		function __destruct() {
			curl_multi_close($this->curlMulti);
		}
		
		private function parseHeaders($header) {
			$headers = array();
			$fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $header));
			$responseCodeHeader = explode(' ', $fields[0]);
			$headers['ResponseCode'] = $responseCodeHeader[1];
			foreach($fields as $field) {
				if(preg_match('/([^:]+): (.+)/m', $field, $match)) {
					//$match[1] = preg_replace('/(?<=^|[\x09\x20\x2D])./e', 'strtoupper("\0")', strtolower(trim($match[1])));
					$match[1] = preg_replace_callback(
							'/(?<=^|[\x09\x20\x2D])./',
							function ($matches) {
								return strtoupper($matches[0]);
							},
							strtolower(trim($match[1])));

					if(isset($headers[$match[1]])) {
						$headers[$match[1]] = array($headers[$match[1]], $match[2]);
					} else {
						$headers[$match[1]] = trim($match[2]);
					}
				}
			}
			return $headers;
		}

		private function getToken() {
			$ch = curl_init();
			
			curl_setopt($ch, CURLOPT_URL, $this->authURL);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_HEADER, 1);
			
			$headers = array (
				'Content-Type: application/x-www-form-urlencoded'
			);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

			$fields = array(
				'client_id' => $this->clientId,
				'client_secret' => $this->clientSecret,
				'grant_type' => 'client_credentials'
			);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
			
			$response = curl_exec($ch);
			
			$return = array();
			$return['response'] = $response;

			$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
			$return['headers'] = $this->parseHeaders(substr($return['response'], 0, $headerSize));
			$return['auth'] = json_decode(substr($return['response'], $headerSize));

			curl_close($ch);

			if($return['headers']['ResponseCode'] == 200) {
				return $return;
			} else {
				$authToken = null;
				echo($response);
				return false;
			}
		}
		
		private function ensureToken() {
			if($this->authToken === null || $this->authTime <= (time() - 3)) {
				$success = false;
				$attempts = 0;
				do {
					$attempts++;
					$response = $this->getToken();
					if($response !== false) {
						$success = true;
						$this->authTime = time() + $response['auth']->expires_in;
						$this->authToken = $response['auth']->access_token;
					}
				} while(!$success && $attempts < $this->maxAttempts);
			}
		}

		private function curlExecWithMulti($handle) {
			curl_multi_add_handle($this->curlMulti, $handle);
			
			$running = 0;
			do {
				curl_multi_exec($this->curlMulti, $running);
				curl_multi_select($this->curlMulti);
			} while($running > 0);

			$output = curl_multi_getcontent($handle);
			curl_multi_remove_handle($this->curlMulti, $handle);
			return $output;
		}

		private function tryCall($uri, $expectedCode, $extraHeaders = null, $method = 'GET', $postData = null) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $this->baseURL . '/' . $this->accountId . '/' . $uri);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_HEADER, 1);

			if($method == 'POST') {
				curl_setopt($ch, CURLOPT_POST, 1);
				if($postData != null) {
					curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
				}
			}

			$headers = array (
				'Connection: Keep-Alive',
				'Keep-Alive: 300',
				'Authorization: Bearer ' . $this->authToken,
				'Content-Type: application/vnd.flowmailer.v' . $this->apiVersion . '+json;charset=UTF-8',
				'Accept: application/vnd.flowmailer.v' . $this->apiVersion . '+json;charset=UTF-8',
				'Expect: '
			);
			
			if($extraHeaders !== null) {
				$headers = array_merge($headers, $extraHeaders);
			}
			
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

			$return = array();
			//$return['response'] = $this->curlExecWithMulti($ch);
			$return['response'] = curl_exec($ch);
			if($return['response'] === false) {
				#echo('cURL returned false' . "\n");
				#print_r(curl_getinfo($ch));
			}

			$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
			$return['headers'] = $this->parseHeaders(substr($return['response'], 0, $headerSize));
			
			$return['data'] = json_decode(substr($return['response'], $headerSize));
			
			curl_close($ch);

			return $return;
		}

		public function call($uri, $expectedCode, $headers = null, $method = 'GET', $postData = null) {
			$this->ensureToken();
			
			$success = false;
			$attempts = 0;
			$return = null;
			do {
				$attempts++;
				$return = $this->tryCall($uri, $expectedCode, $headers, $method, $postData);
				if($return['headers']['ResponseCode'] == $expectedCode) {
					$success = true;
					return $return;
				}

				if($return['headers']['ResponseCode'] == 401) {
					$this->ensureToken();
					continue;
				}
				
				sleep(1);
			} while(!$success && $attempts < $this->maxAttempts);

                        throw new \Exception(print_r($return, true));
		}
		
		public function listCall($uri, $offset, $batchSize) {
			$lower = $offset;
			$upper = $offset + $batchSize;
			
			$headers = array(
				'range: items=' . $lower . '-' . $upper
			);
			
			return $this->call($uri, 206, $headers);
		}
		
		public function submitMessage(SubmitMessage $message) {
			return $this->call('/messages/submit', 201, null, 'POST', json_encode($message));
		}
		
		public function undeliveredMessages(DateTime $receivedFrom, DateTime $receivedTo, $addEvents = false, $addHeaders = false, $addOnlineLink = false) {
			$uri  = '/undeliveredmessages';
			
			$dateFrom = clone $receivedFrom;
			$dateFrom = $dateFrom->modify('-7 day'); //bounces can be received a week after sending
			$uri .= ';daterange=' . $dateFrom->format(DATE_ISO8601) . ',' . $receivedTo->format(DATE_ISO8601);
			$uri .= ';receivedrange=' . $receivedFrom->format(DATE_ISO8601) . ',' . $receivedTo->format(DATE_ISO8601);
			$uri .= '?addheaders=' . ($addHeaders ? 'true' : 'false');
			$uri .= '&addevents=' . ($addEvents ? 'true' : 'false');
			$uri .= '&addonlinelink=' . ($addOnlineLink ? 'true' : 'false');
			
			$done = false;
			$offset = 0;
			$batchSize = 100;
			
			$result = array();
			while(!$done) {
				$newResult = $this->listCall($uri, $offset, $batchSize);
				$result = array_merge($result, $newResult['data']);
				$offset += $batchSize;
				
				if(count($newResult['data']) < $batchSize) {
					$done = true;
				}
			}
			
			return $result;
		}


		public function messages(DateTime $submittedFrom, DateTime $submittedTo, $addEvents = false, $addHeaders = false, $addOnlineLink = false) {
			$uri  = '/messages';			
			$uri .= ';daterange=' . $submittedFrom->format(DATE_ISO8601) . ',' . $submittedTo->format(DATE_ISO8601);
			$uri .= '?addheaders=' . ($addHeaders ? 'true' : 'false');
			$uri .= '&addevents=' . ($addEvents ? 'true' : 'false');
			$uri .= '&addonlinelink=' . ($addOnlineLink ? 'true' : 'false');
			
			$done = false;
			$offset = 0;
			$batchSize = 100;
			
			$result = array();
			while(!$done) {
				echo($offset . ' ');
				$newResult = $this->listCall($uri, $offset, $batchSize);
				
				if(!is_array($newResult['data']) || count($newResult['data']) < $batchSize) {
					$done = true;
				}

				if(is_array($newResult['data'])) {
					$result = array_merge($result, $newResult['data']);
				}
				$offset += $batchSize;
			}
			
			return $result;
		}
	}
?>
