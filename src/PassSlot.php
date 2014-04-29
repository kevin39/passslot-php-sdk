<?php
/**
 * Licensed under the Apache License, Version 2.0 (the "License"); you may
 * not use this file except in compliance with the License. You may obtain
 * a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations
 * under the License.
 *
 * @copyright  Copyright (c) 2013 PassSlot (http://www.passslot.com)
 * @license    http://www.apache.org/licenses/LICENSE-2.0  Apache 2.0
 * @version    $Id:$
 */

if (!function_exists('curl_init')) {
	throw new Exception('PassSlot needs the CURL PHP extension.');
}
if (!function_exists('json_decode')) {
	throw new Exception('PassSlot needs the JSON PHP extension.');
}
if (!function_exists('mime_content_type')) {
	throw new Exception('PassSlot needs the FILEINFO PHP extension.');
}

/**
 * This class is the core of PassSlot.
 *
 * <p>You can start PassSlot using your app key. You can then create passes from templates, change values of passes or add passes to passbook.</p>

 * <code>
 * $engine = PassSlot::start($appKey);
 * $values = array(
 *	'Name' => 'John',
 *	'Level' => 'Platinum',
 *	'Balance' => 20.50
 * );
 *
 * $images = array(
 *	'thumbnail' => dirname(__FILE__) . '/john.png'
 * );
 *
 * $pass = $engine->createPassFromTemplate(6008004, $values, $images);
 * $passData = $engine->downloadPass($pass);
 * $engine->redirectToPass($pass);
 * </code>
 */
class PassSlot {

	/**
	 * @var string App Key
	 */
	private $_appKey = null;

	/**
	 * PassSlot API Endpoint
	 * @var string PassSlot API Endpoint
	 */
	private $_endpoint = 'https://api.passslot.com/v1';

	/**
	 * Change to see HTTP request
	 */
	private $_debug = false;

	/**
	 * @var \PassSlot Shared class instance of PassSlot when using PassSlot::start
	 */
	private static $_instance = null;

	/**
	 * Allowed image types that are supported by PassSlot and Passbook
	 */
	private static $_imageTypes = array('icon', 'logo', 'strip', 'thumbnail', 'background', 'footer');

	/**
	 * SDK Version
	 */
	const VERSION = '0.2';

	/**
	 * Default user agent
	 */
	const USER_AGENT = 'PassSlotSDK-PHP/0.2';

	/**
	 * Creates a new PassSlot instance
	 *
	 * @param string $appKey App Key
	 */
	public function __construct($appKey = null) {
		if (is_null($appKey)) {
			throw new Exception('App Key required');
		}
		$this -> _appKey = $appKey;
	}

	/**
	 * Start PassSlot with an app key
	 *
	 * @param string $appKey App Key
	 * @return PassSlot Shared PassSlot instance.
	 */
	public static function start($appKey = null) {
		if (self::$_instance == null) {
			self::$_instance = new self($appKey);
		}
		return self::$_instance;
	}

	/**
	 * Create a pass based on an existing template.
	 *
	 * For the values form the array like this:
	 * <code>
	 * $values = array(
	 * 	  'placeholderName1' => 'placeholderValue1',
	 *    'placeholderName2' => 'placeholderValue2'
	 * );
	 * </code>
	 *
	 * For the images form the array like this
	 * <code>
	 * $images = array(
	 * 	  'icon' => 'path/to/icon.png',
	 *    'icon2x' => 'path/to/icon@2x'
	 * );
	 * </code>
	 *
	 * For allowed image types, see $_imageTypes. Retina images can be added
	 * by appending 2x to the image types.
	 * @see PassSlot::$_imageTypes
	 *
	 * @param int $templateId Template ID
	 * @param array $values Values for the placeholders
	 * @param array $images Images that should be used for the pass
	 *
	 * @throws PassSlotApiException API Exception
	 */
	public function createPassFromTemplate($templateId, $values = array(), $images = array()) {
		$resource = sprintf("/templates/%s/pass", $templateId);
		return $this -> _createPass($resource, $values, $images);
	}

	/**
	 * Same function as createPassFromTemplate but you can provide the template name instead of the template id
	 *
	 * @param string $templateName Template Name
	 * @param array $values Values for the placeholders
	 * @param array $images Images that should be used for the pass
	 * @see PassSlot::createPassFromTemplate()
	 * @throws PassSlotApiException API Exception
	 */
	public function createPassFromTemplateWithName($templateName, $values = array(), $images = array()) {
		$resource = sprintf("/templates/names/%s/pass", rawurlencode($templateName));
		return $this -> _createPass($resource, $values, $images);
	}

	/**
	 * Downloads the pkpass file
	 *
	 * @param object $pass The pass created with PassSlot::createPassFromTemplate() or PassSlot::createPassFromTemplateWithName()
	 * @return string pkpass data
	 * @throws PassSlotApiException API Exception
	 */
	public function downloadPass($pass) {
		$resource = sprintf("/passes/%s/%s", $pass -> passTypeIdentifier, $pass -> serialNumber);
		return $this -> _restCall('GET', $resource);
	}

	/**
	 * Outputs a pkpass file with proper content headers for Passbook.
	 * Make sure that your script does not output anything before calling this method,
	 * otherwise the headers can't be send.
	 *
	 * @param string $pass The pass data
	 * @param string $fileName Optional file name for the attachment
	 */
	public function outputPass($pass, $fileName = 'pass.pkpass') {
		if (!is_string($pass)) {
			$pass = $this -> downloadPass($pass);
		}

		header('Pragma: no-cache');
		header('Content-type: application/vnd.apple.pkpass');
		header('Content-length: ' . strlen($pass));
		header('Content-Disposition: attachment; filename="' . $fileName . '"');
		echo $pass;
	}

	/**
	 * Gets the url of the pass preview
	 *
	 * @param object $pass Existing pass
	 * @return string The pass preview url
	 * @throws PassSlotApiException API Exception
	 */
	public function getPassURL($pass) {
		if ($pass -> url) {
			return $pass -> url;
		}
		$resource = sprintf("/passes/%s/%s/url", $pass -> passTypeIdentifier, $pass -> serialNumber);
		return $this -> _restCall('GET', $resource) -> url;
	}

	/**
	 * Redirects the user to the pass url using a HTTP redirection
	 *
	 * @param object $pass Existing pass
	 * @throws PassSlotApiException API Exception
	 */
	public function redirectToPass($pass) {
		$url = $this -> getPassURL($pass);
		header("Location: " . $url);
	}

	/**
	 * Emails the pass to the specified email address
	 *
	 * @param object $pass Existing pass
	 * @param string $email Recipient's email address
	 * @throws PassSlotApiException API Exception
	 */
	public function emailPass($pass, $email) {
		$resource = sprintf("/passes/%s/%s/email", $pass -> passTypeIdentifier, $pass -> serialNumber);
		$content = array("email" => $email);
		$this -> _restCall('POST', $resource, $content);
	}

	/**
	 * Prepares the values and image for the pass and creates it
	 *
	 * @param string $resource Resource URL for the pass creation
	 * @param array $values Values
	 * @param array $images Images
	 * @return object Pass
	 */
	private function _createPass($resource, $values, $images) {
		$multipart = count($images) > 0;

		if ($multipart) {
			$content = array();

			foreach ($images as $imageType => $image) {
				if (!in_array($imageType, self::$_imageTypes) && !in_array($imageType . '2x', self::$_imageTypes)) {
					user_error('Image type ' . $imageType . ' not available. Image will be ignored', E_USER_WARNING);
					continue;
				}

				if (!is_file($image)) {
					user_error('No such image  ' . $image . '. Image will be ignored', E_USER_WARNING);
					continue;
				}

				$mimeType = mime_content_type($image);
				if ($mimeType != 'image/png' && $mimeType != 'image/jpg' && $mimeType != 'image/gif') {
					user_error('Image mime type ' . $mimeType . ' not supported. Image will be ignored', E_USER_WARNING);
					continue;
				}

				$content[$imageType] = sprintf('@%s;type=%s', $image, $mimeType);
			}

			// Write json to file for curl
			$jsonPath = array_search('uri', @array_flip(stream_get_meta_data(tmpfile())));
			file_put_contents($jsonPath, json_encode($values));
			$content['values'] = sprintf('@%s;type=application/json', $jsonPath);

		} else {
			$content = $values;
		}
		return $this -> _restCall('POST', $resource, $content, $multipart);
	}

	/**
	 * Performs an API call
	 *
	 * @param string $httpMethod HTTP Method (e.g. GET, POST)
	 * @param string $resource Resource URL of the call
	 * @param mixed $content Content/Body of the call.
	 * @param bool $multipart Whether $content respresent a multipart message
	 *
	 * @return mixed Response
	 * @throws PassSlotApiException API Exception
	 */
	private function _restCall($httpMethod, $resource, $content = null, $multipart = false) {

		$ch = curl_init($this -> _endpoint . $resource);

		$httpHeaders = array('Accept:application/json, */*; q=0.01');

		if ($this -> _debug) {
			curl_setopt($ch, CURLOPT_VERBOSE, 1);
		}

		if ($httpMethod == 'POST' || $httpMethod == 'PUT') {
			curl_setopt($ch, CURLOPT_POST, 1);

			if ($multipart) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
			} else {
				if ($content == null) {
					$content = json_decode('{}');
				}
				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($content));
				$httpHeaders[] = 'Content-Type: application/json';
			}
		} else if ($httpMethod == 'DELETE') {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $httpMethod);
		}

		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD, $this -> _appKey . ':');
		curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

		// Add ca certs so that we can for sure properly verify SSL certificates
		if (file_exists(dirname(__FILE__) . '/cacert.pem')) {
			// cacert.pem from http://curl.haxx.se/docs/caextract.html
			curl_setopt($ch, CURLOPT_CAINFO, dirname(__FILE__) . '/cacert.pem');
		}

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
		curl_close($ch);

		if ($httpCode == 422) {
			// Validation Error
			throw new PassSlotApiValidationException($response);
		}

		if ($httpCode == 401) {
			throw new PassSlotApiUnauthorizedException();
		}

		if ($httpCode < 200 || $httpCode >= 300) {
			throw new PassSlotApiException($response, $httpCode);
		}

		if (strpos($contentType, "application/json") === 0) {
			$json = json_decode($response);
			return $json;
		}

		return $response;
	}

}

/**
 * This exception represents a generic API exception
 */
class PassSlotApiException extends Exception {

	/**
	 * Creates an API exception
	 *
	 * @param string $message Error message
	 * @param int $code HTTP status code
	 */
	public function __construct($message, $code = 0) {
		$json = json_decode($message);
		parent::__construct($json ? $json -> message : $message, $code);
	}

	/**
	 * Returns string representation of an api exception
	 *
	 * @return string String representation of exception
	 */
	public function __toString() {
		return "[{$this->code}]: {$this->message}\n";
	}

	/**
	 * Return the HTTP statuc code
	 * @return int HTTP status code
	 */
	public function getHTTPCode() {
		return parent::getCode();
	}

}

/**
 * This exception represents an Unauthorized exception. This exception will be thrown the the app key is invalid
 */
class PassSlotApiUnauthorizedException extends PassSlotApiException {

	/**
	 * Creates an unauthorized exception.
	 */
	public function __construct() {
		parent::__construct('Unauthorized. Please check your app key and make sure it has access to the template and pass type id', 401);
	}

}

/**
 * This exception represents an Validation exception. This exception will be thrown if the validation
 * of the pass values or other conent has failed.
 */
class PassSlotApiValidationException extends PassSlotApiException {

	/**
	 * Creates an validation exception based on API response
	 *
	 * @param string $response JSON resonse string from API call
	 */
	public function __construct($response) {
		$json = json_decode($response);
		if ($json) {
			$msg = $json -> message;
			foreach ($json->errors as $error) {
				$msg .= '; ' . $error -> field . ': ' . implode(', ', $error -> reasons);
			}
			parent::__construct($msg, 422);
		} else {
			parent::__construct('Validation Failed', 422);
		}
	}

}
