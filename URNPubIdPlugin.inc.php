<?php

/**
 * @file plugins/pubIds/urn/URNPubIdPlugin.inc.php
 *
 * Copyright (c) 2015 Heidelberg University
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class URNPubIdPlugin
 * @ingroup plugins_pubIds_urn
 *
 * @brief URN plugin class
 */


import('classes.plugins.PubIdPlugin');

class URNPubIdPlugin extends PubIdPlugin {

	//
	// Implement template methods from PKPPlugin.
	//
	/**
	 * @see PubIdPlugin::register()
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		$this->addLocaleData();
		return $success;
	}

	/**
	 * @see PKPPlugin::getName()
	 */
	function getName() {
		return 'URNPubIdPlugin';
	}

	/**
	 * @see PKPPlugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.pubIds.urn.displayName');
	}

	/**
	 * @see PKPPlugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.pubIds.urn.description');
	}

	/**
	 * @see Plugin::getTemplatePath($inCore)
	 */
	function getTemplatePath($inCore = false) {
		return parent::getTemplatePath($inCore) . 'templates/';
	}

	/**
	 * Define management link actions for the settings verb.
	 * @return LinkAction
	 */
	function getManagementVerbLinkAction($request, $verb) {
		$router = $request->getRouter();

		list($verbName, $verbLocalized) = $verb;

		if ($verbName === 'settings') {
			import('lib.pkp.classes.linkAction.request.AjaxLegacyPluginModal');
			$actionRequest = new AjaxLegacyPluginModal(
					$router->url($request, null, null, 'plugin', null, array('verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'pubIds')),
					$this->getDisplayName()
			);
			return new LinkAction($verbName, $actionRequest, $verbLocalized, null);
		}

		return null;
	}
	
	/**
	 * @see PKPPlugin::manage()
	 */
	function manage($verb, $args, &$message, &$messageParams, &$pluginModalContent = null) {
		$request = $this->getRequest();
		$templateManager = TemplateManager::getManager($request);
		$templateManager->register_function('plugin_url', array(&$this, 'smartyPluginUrl'));
		if (!$this->getEnabled() && $verb != 'enable') return false;
		switch ($verb) {
			case 'enable':
				$this->setEnabled(true);
				$message = NOTIFICATION_TYPE_PLUGIN_ENABLED;
				$messageParams = array('pluginName' => $this->getDisplayName());
				return false;
	
			case 'disable':
				$this->setEnabled(false);
				$message = NOTIFICATION_TYPE_PLUGIN_DISABLED;
				$messageParams = array('pluginName' => $this->getDisplayName());
				return false;
	
			case 'settings':
				$press = $request->getPress();
	
				$settingsFormName = $this->getSettingsFormName();
				$settingsFormNameParts = explode('.', $settingsFormName);
				$settingsFormClassName = array_pop($settingsFormNameParts);
				$this->import($settingsFormName);
				$form = new $settingsFormClassName($this, $press->getId());
				if ($request->getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						$message = NOTIFICATION_TYPE_SUCCESS;
						$messageParams = array('contents' => __('plugins.pubIds.urn.manager.settings.urnSettingsUpdated'));
						return false;
					} else {
						$pluginModalContent = $form->fetch($request);
					}
				} else {
					$form->initData();
					$pluginModalContent = $form->fetch($request);
				}
				return false;
			default:
				// Unknown management verb
				assert(false);
				return false;
		}
	}

	//
	// Implement template methods from PubIdPlugin.
	//
	/**
	 * @see PubIdPlugin::getPubId()
	 */
	function getPubId($pubObject, $preview = false) {
		// Determine the type of the publishing object.
		$pubObjectType = $this->getPubObjectType($pubObject);

		// Initialize variables for publication objects.
		$publicationFormat = ($pubObjectType == 'PublicationFormat' ? $pubObject : null);
		$monograph = ($pubObjectType == 'Monograph' ? $pubObject : null);

		// Get the press id of the object.
		if (in_array($pubObjectType, array('PublicationFormat', 'Monograph'))) {
			$pressId = $pubObject->getContextId();
		} else {
			return null;
		}

		$press = $this->_getPress($pressId);
		if (!$press) return null;
		$pressId = $press->getId();

		// If we already have an assigned URN, use it.
		$storedURN = $pubObject->getStoredPubId('urn');
		if ($storedURN) return $storedURN;
		
		// Retrieve the URN prefix.
		$urnPrefix = $this->getSetting($pressId, 'urnPrefix');
		if (empty($urnPrefix)) return null;

		// Generate the URN suffix.
		$urnSuffixGenerationStrategy = $this->getSetting($pressId, 'urnSuffix');

		switch ($urnSuffixGenerationStrategy) {
/* 			case 'customId':
				$urnSuffix = $pubObject->getData('urnSuffix');
				break;
 */
			case 'pattern':
				$urnSuffix = $this->getSetting($pressId, "urn${pubObjectType}SuffixPattern");

				// %p - press initials
				$urnSuffix = String::regexp_replace('/%p/', String::strtolower($press->getPath()), $urnSuffix);

				if ($publicationFormat) {
					// %m - monograph id, %f - publication format id
					$urnSuffix = String::regexp_replace('/%m/', $publicationFormat->getMonographId(), $urnSuffix);
					$urnSuffix = String::regexp_replace('/%f/', $publicationFormat->getId(), $urnSuffix);
				}
				if ($monograph) {
					// %m - monograph id
					$urnSuffix = String::regexp_replace('/%m/', $monograph->getId(), $urnSuffix);
				}

				break;

			default:
				$urnSuffix = String::strtolower($press->getPath());

				if ($publicationFormat) {
					$urnSuffix .= '.' . $publicationFormat->getMonographId();
 					$urnSuffix .= '.' . $publicationFormat->getId();
				}
				if ($monograph) {
					$urnSuffix .= '.' . $monograph->getId();
				}
		}
		if (empty($urnSuffix)) return null;

		// Join prefix and suffix.
		$urn = $urnPrefix . $urnSuffix;

		if (!$preview) {
			// Save the generated URN.
			$this->setStoredPubId($pubObject, $pubObjectType, $urn);
		}

		return $urn;
	}

	/**
	 * @see PubIdPlugin::getPubIdType()
	 */
	function getPubIdType() {
		return 'urn';
	}

	/**
	 * @see PubIdPlugin::getPubIdDisplayType()
	 */
	function getPubIdDisplayType() {
		return 'URN';
	}

	/**
	 * @see PubIdPlugin::getPubIdFullName()
	 */
	function getPubIdFullName() {
		return 'Uniform Resource Name';
	}

	/**
	 * @see PubIdPlugin::getResolvingURL()
	 */
	function getResolvingURL($pressId, $pubId) {
		return 'https://nbn-resolving.org/'.urlencode($pubId);
	}

	/**
	 * @see PubIdPlugin::getFormFieldNames()
	 */
	function getFormFieldNames() {
		return array('urnSuffix');
	}

	/**
	 * @see PubIdPlugin::getDAOFieldNames()
	 */
	function getDAOFieldNames() {
		return array('pub-id::urn');
	}

	/**
	 * @see PubIdPlugin::getPubIdMetadataFile()
	 */
	function getPubIdMetadataFile() {
		return $this->getTemplatePath().'urnSuffixEdit.tpl';
	}

	/**
	 * @see PubIdPlugin::getSettingsFormName()
	 */
	function getSettingsFormName() {
		return 'form.URNSettingsForm';
	}

	/**
	 * @see PubIdPlugin::verifyData()
	 */
	function verifyData($fieldName, $fieldValue, &$pubObject, $pressId, &$errorMsg) {
/* 		// Verify URN uniqueness.
		assert($fieldName == 'urnSuffix');
		if (empty($fieldValue)) return true;

		// Construct the potential new URN with the posted suffix.
		$urnPrefix = $this->getSetting($pressId, 'urnPrefix');
		if (empty($urnPrefix)) return true;
		$newUrn = $urnPrefix . '/' . $fieldValue;

		if($this->checkDuplicate($newUrn, $pubObject, $pressId)) {
			return true;
		} else {
			$errorMsg = __('plugins.pubIds.urn.editor.urnSuffixCustomIdentifierNotUnique');
			return false;
		} */
		return True;
	}

	/**
	 * @see PubIdPlugin::validatePubId()
	 */
	function validatePubId($pubId) {
		$urnParts = explode(':', $pubId, 2);
		return count($urnParts) == 2 and substr($pubId, 0, 3) == "urn:";
	}


	//
	// Private helper methods
	//
	/**
	 * Get the press object.
	 * @param $pressId integer
	 * @return Press
	 */
	function &_getPress($pressId) {
		assert(is_numeric($pressId));

		// Get the press object from the context (optimized).
		$request = $this->getRequest();
		$router = $request->getRouter();
		$press = $router->getContext($request); /* @var $press Press */

		// Check whether we still have to retrieve the press from the database.
		if (!$press || $press->getId() != $pressId) {
			unset($press);
			$pressDao = DAORegistry::getDAO('PressDAO');
			$press = $pressDao->getById($pressId);
		}

		return $press;
	}
}

?>