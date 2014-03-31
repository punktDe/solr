<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2010-2011 Timo Schmidt <timo.schmidt@aoemedia.de>
*  (c) 2012 Ingo Renner <ingo@typo3.org>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/


/**
 * Abstract base class for all solr plugins.
 *
 * Implements a main method and several abstract methods which
 * need to be implemented by an inheriting plugin.
 *
 * @author	Ingo Renner <ingo@typo3.org>
 * @author	Timo Schmidt <timo.schmidt@aoemedia.de
 * @package	TYPO3
 * @subpackage	solr
 */
abstract class Tx_Solr_PluginBase_PluginBase extends tslib_pibase {

	public $prefixId = 'tx_solr';
	public $extKey   = 'solr';

	/**
	 * an instance of Tx_Solr_Search
	 *
	 * @var Tx_Solr_Search
	 */
	protected $search;

	/**
	 * The plugin's query
	 *
	 * @var Tx_Solr_Query
	 */
	protected $query = NULL;

	/**
	 * Determines whether the solr server is available or not.
	 */
	protected $solrAvailable;

	/**
	 * An instance of Tx_Solr_Template
	 *
	 * @var Tx_Solr_Template
	 */
	protected $template;

	/**
	 * An instance of Tx_Solr_JavascriptManager
	 *
	 * @var Tx_Solr_JavascriptManager
	 */
	protected $javascriptManager;

	/**
	 * The user's raw query.
	 *
	 * Private to enforce API usage.
	 *
	 * @var string
	 */
	private $rawUserQuery;


	// Main


	/**
	 * The main method of the plugin
	 *
	 * @param	string	The plugin content
	 * @param	array	The plugin configuration
	 * @return	string	The content that is displayed on the website
	 */
	public function main($content, $configuration) {
		$content = '';

		try {
			$this->initialize($configuration);
			$this->preRender();

			$actionResult = $this->performAction();

			if ($this->solrAvailable) {
				$content = $this->render($actionResult);
			} else {
				$content = $this->renderError();
			}

			$content = $this->postRender($content);
		} catch(Exception $e) {
			if ($GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_solr.']['logging.']['exceptions']) {
				t3lib_div::devLog(
					$e->getCode() . ': ' . $e->__toString(),
					'solr',
					3,
					(array) $e
				);
			}

			$this->initializeTemplateEngine();
			$content = $this->renderException();
		}

		return $this->baseWrap($content);
	}

	/**
	 * Adds the possibility to use stdWrap on the plugins content instead of wrapInBaseClass.
	 * Defaults to wrapInBaseClass to ensure downward compatibility.
	 *
	 * @param $content The plugin content
	 * @return string
	 */
	protected function baseWrap($content) {
		if (isset($this->conf['general.']['baseWrap.'])) {
			return $this->cObj->stdWrap($content, $this->conf['general.']['baseWrap.']);
		} else {
			return $this->pi_wrapInBaseClass($content);
		}
	}

	/**
	 * Implements the action logic. The result of this method is passed to the
	 * render method.
	 *
	 * @return string Action result
	 */
	protected abstract function performAction();


	// Initialization


	/**
	 * Initializes the plugin - configuration, language, caching, search...
	 *
	 * @param	array	configuration array as provided by the TYPO3 core
	 */
	protected function initialize($configuration) {
		$this->conf = $configuration;

		$this->conf = t3lib_div::array_merge_recursive_overrule(
			$GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_solr.'],
			$this->conf
		);

		$this->pi_setPiVarDefaults();
		$this->pi_loadLL();
		$this->pi_initPIflexForm();
		$this->overrideTyposcriptWithFlexformSettings();

		$this->initializeQuery();
		$this->initializeSearch();
		$this->initializeTemplateEngine();
		$this->initializeJavascriptManager();

		$this->postInitialize();
	}

	/**
	 * Overload pi_setPiVarDefaults to add stdWrap-functionality to _DEFAULT_PI_VARS
	 *
	 * @author Grigori Prokhorov <grigori.prokhorov@dkd.de>
	 * @author Ivan Kartolo <ivan.kartolo@dkd.de>
	 * @return void
	 */
	function pi_setPiVarDefaults() {
		if (is_array($this->conf['_DEFAULT_PI_VARS.'])) {
			foreach ($this->conf['_DEFAULT_PI_VARS.'] as $key => $defaultValue) {
				$this->conf['_DEFAULT_PI_VARS.'][$key] = $this->cObj->cObjGetSingle($this->conf['_DEFAULT_PI_VARS.'][$key], $this->conf['_DEFAULT_PI_VARS.'][$key . '.']);
			}

			$this->piVars = t3lib_div::array_merge_recursive_overrule(
				$this->conf['_DEFAULT_PI_VARS.'],
				is_array($this->piVars) ? $this->piVars : array()
			);
		}
	}

	/**
	 * Allows to override TypoScript settings with Flexform values.
	 *
	 */
	protected function overrideTyposcriptWithFlexformSettings() {}

	/**
	 * Initializes the query from the GET query parameter.
	 *
	 */
	protected function initializeQuery() {
		$this->rawUserQuery = t3lib_div::_GET('q');
	}

	/**
	 * Initializes the Solr connection and tests the connection through a ping.
	 *
	 */
	protected function initializeSearch() {
		$solrConnection = t3lib_div::makeInstance('Tx_Solr_ConnectionManager')->getConnectionByPageId(
			$GLOBALS['TSFE']->id,
			$GLOBALS['TSFE']->sys_language_uid,
			$GLOBALS['TSFE']->MP
		);

		$this->search = t3lib_div::makeInstance('Tx_Solr_Search', $solrConnection);
		$this->solrAvailable = $this->search->ping();
	}

	/**
	 * Initializes the template engine and returns the initialized instance.
	 *
	 * @return	Tx_Solr_Template
	 */
	protected function initializeTemplateEngine() {
		$templateFile = $this->getTemplateFile();
		$subPart      = $this->getSubpart();

		$flexformTemplateFile = $this->pi_getFFvalue(
			$this->cObj->data['pi_flexform'],
			'templateFile',
			'sOptions'
		);
		if (!empty($flexformTemplateFile)) {
			$templateFile = $flexformTemplateFile;
		}

		$template = t3lib_div::makeInstance(
			'Tx_Solr_Template',
			$this->cObj,
			$templateFile,
			$subPart
		);
		$template->addViewHelperIncludePath($this->extKey, 'Classes/ViewHelper/');
		$template->addViewHelper('LLL', array(
			'languageFile' => $GLOBALS['PATH_solr'] .'Resources/Private/Language/' . str_replace('Pi', 'Plugin', $this->getPluginKey()) . '.xml',
			'llKey'        => $this->LLkey
		));

			// can be used for view helpers that need configuration during initialization
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr'][$this->getPluginKey()]['addViewHelpers'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr'][$this->getPluginKey()]['addViewHelpers'] as $classReference) {
				$viewHelperProvider = &t3lib_div::getUserObj($classReference);

				if ($viewHelperProvider instanceof Tx_Solr_ViewHelperProvider) {
					$viewHelpers = $viewHelperProvider->getViewHelpers();
					foreach ($viewHelpers as $helperName => $helperObject) {
						$helperAdded = $template->addViewHelperObject($helperName, $helperObject);
							// TODO check whether $helperAdded is TRUE, throw an exception if not
					}
				} else {
					throw new UnexpectedValueException(
						get_class($viewHelperProvider) . ' must implement interface Tx_Solr_ViewHelperProvider',
						1310387296
					);
				}
			}
		}

		$template = $this->postInitializeTemplateEngine($template);

		$this->template = $template;
	}

	/**
	 * Initializes the javascript manager.
	 *
	 */
	protected function initializeJavascriptManager() {
		$this->javascriptManager = t3lib_div::makeInstance('Tx_Solr_JavascriptManager');
	}

	/**
	 * This method is called after initializing in the initialize method.
	 * Overwrite this method to do your own initialization.
	 *
	 * @return void
	 */
	protected function postInitialize() {}

	/**
	 * Overwrite this method to do own initialisations  of the template.
	 *
	 * @param $template
	 * @return $templae
	 */
	protected function postInitializeTemplateEngine($template) {
		return $template;
	}


	// Rendering


	/**
	 * This method executes the requested commands and applies the changes to
	 * the template.
	 *
	 * @return string $actionResult Rendered plugin content
	 */
	protected abstract function render($actionResult);

	/**
	 * Renders a solr error.
	 *
	 * @return	string	A representation of the error that should be understandable for the user.
	 */
	protected function renderError() {
		$this->template->workOnSubpart('solr_search_unavailable');

		return $this->template->render();
	}

	/**
	 * Renders a solr exception.
	 *
	 * @return	string	A representation of the exception that should be understandable for the user.
	 */
	protected function renderException() {
		$this->template->workOnSubpart('solr_search_error');

		return $this->template->render();
	}

	/**
	 * Should be overwritten to do things before rendering.
	 *
	 */
	protected function preRender() {}

	/**
	 * Overwrite this method to perform changes to the content after rendering.
	 *
	 * @param	string	The content rendered by the plugin so far
	 * @return	string	The content that should be presented on the website, might be different from the output rendered before
	 */
	protected function postRender($content) {
		if (isset($this->conf['stdWrap.'])) {
			$content = $this->cObj->stdWrap($content, $this->conf['stdWrap.']);
		}

		return $content;
	}


	// Helper methods


	/**
	 * Determines the template file from the configuration.
	 *
	 * Overwrite this method to use a diffrent template.
	 *
	 * @return	string	The template file name to be used for the plugin
	 */
	protected function getTemplateFile() {
		return $this->conf['templateFiles.'][$this->getTemplateFileKey()];
	}

	/**
	 * This method should be implemented to return the TSconfig key which
	 * contains the template name for this template.
	 *
	 * @see	Tx_Solr_PluginBase_PluginBase#initializeTemplateEngine()
	 * @return	string	The TSconfig key containing the template name
	 */
	protected abstract function getTemplateFileKey();

	/**
	 * Gets the plugin's template instance.
	 *
	 * @return	Tx_Solr_Template	The plugin's template.
	 */
	public function getTemplate() {
		return $this->template;
	}

	/**
	 * Gets the plugin's javascript manager.
	 *
	 * @return Tx_Solr_JavascriptManager The plugin's javascript manager.
	 */
	public function getJavascriptManager() {
		return $this->javascriptManager;
	}

	/**
	 * Should return the relevant subpart of the template.
	 *
	 * @see	Tx_Solr_PluginBase_PluginBase#initializeTemplateEngine()
	 * @return	string	the subpart of the template to be used
	 */
	protected abstract function getSubpart();

	/**
	 * This method should return the plugin key. Reads some configuration
	 * options in initializeTemplateEngine()
	 *
 	 * @see	Tx_Solr_pluginBase_PluginBase#initializeTemplateEngine()
	 * @return	string	The plugin key
	 */
	protected abstract function getPluginKey();

	/**
	 * Gets the target page Id for links. Might have been set through either
	 * flexform or TypoScript. If none is set, TSFE->id is used.
	 *
	 * @return	integer	The page Id to be used for links
	 */
	public function getLinkTargetPageId() {
		return $this->conf['search.']['targetPage'];
	}

	/**
	 * Gets the Tx_Solr_Search instance used for the query. Mainly used as a
	 * helper function for result document modifiers.
	 *
	 * @return	Tx_Solr_Search
	 */
	public function getSearch() {
		return $this->search;
	}

	/**
	 * Sets the Tx_Solr_Search instance used for the query. Mainly used as a
	 * helper function for result document modifiers.
	 *
	 * @param Tx_Solr_Search $search Search instance
	 */
	public function setSearch(Tx_Solr_Search $search) {
		$this->search = $search;
	}

	/**
	 * Gets the user's query term and cleans it so that it can be used in
	 * templates f.e.
	 *
	 * @return string The cleaned user query.
	 */
	public function getCleanUserQuery() {
		$userQuery = $this->getRawUserQuery();

		if (!is_null($userQuery)) {
			$userQuery = Tx_Solr_Query::cleanKeywords($userQuery);
		}

		// escape triple hashes as they are used in the template engine
		// TODO remove after switching to fluid templates
		$userQuery = Tx_Solr_Template::escapeMarkers($userQuery);

		return $userQuery;
	}

	/**
	 * Gets the raw user query
	 *
	 * @return string Raw user query.
	 */
	public function getRawUserQuery() {
		return $this->rawUserQuery;
	}
}


if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/solr/Classes/pluginbase/PluginBase.php'])	{
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/solr/Classes/pluginbase/PluginBase.php']);
}

?>