<?php
/**
 * File containing the ezpLanguageSwitcher class
 *
 * @copyright Copyright (C) 1999-2012 eZ Systems AS. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2
 * @version //autogentag//
 * @package kernel
 */

/**
 * Utility class for transforming URLs between siteaccesses.
 *
 * This class will generate URLs for various siteaccess, and translate
 * URL-aliases into other languages as necessary.
 */
class ezpLanguageSwitcher implements ezpLanguageSwitcherCapable
{
    protected $origUrl;
    protected $userParamString;

    protected $destinationSiteAccess;
    protected $destinationLocale;

    protected $baseDestinationUrl;

    protected $destinationSiteAccessIni;

    function __construct( $params = null )
    {
        if ( $params === null )
        {
            return $this;
        }

        // Removing the first part, which is the SA param.
        $siteini = eZINI::instance( "site.ini");
        $availableSA = $siteini->variable( 'SiteAccessSettings', 'AvailableSiteAccessList' );
        if ( in_array( $params['Parameters'][0], $availableSA ) )
        {
        	array_shift( $params['Parameters'] );
        }
        $this->origUrl = join( $params['Parameters'] , '/' );

        $this->userParamString = '';
        $userParams = $params['UserParameters'];
        foreach ( $userParams as $key => $value )
        {
            $this->userParamString .= "/($key)/$value";
        }
    }

    /**
     * Get instance siteaccess specific site.ini
     *
     * @param string $sa
     * @return void
     */
    protected function getSiteAccessIni()
    {
        if ( $this->destinationSiteAccessIni === null )
        {
            $this->destinationSiteAccessIni = eZSiteAccess::getIni( $this->destinationSiteAccess, 'site.ini' );
        }
        return $this->destinationSiteAccessIni;
    }

    /**
     * Checks if the given $url points to a module.
     *
     * We use this method to check whether we should pass on the original URL
     * to the destination translation siteaccess.
     *
     * @param string $url
     * @return bool
     */
    protected function isUrlPointingToModule( $url )
    {
        // Grab the first URL element, representing the possible module name
        $urlElements = explode( '/', $url );

        // Look up for a match in the module list
        $moduleIni = eZINI::instance( 'module.ini' );
        $availableModules = $moduleIni->variable( 'ModuleSettings', 'ModuleList' );
        return in_array( $urlElements[0], $availableModules, true ) || in_array( $urlElements[1], $availableModules, true );
    }

    /**
     * Checks if the current content object locale is available in destination siteaccess.
     *
     * This is used to check whether we should pass on the original URL to the
     * destination translation siteaccess, when no translation of an object
     * exists in the destination locale.
     *
     * If the current content object locale exists as a fallback in the
     * destination siteaccess, the original URL should be available there as
     * well.
     *
     * @return bool
     */
    protected function isLocaleAvailableAsFallback()
    {
        $currentContentObjectLocale = eZINI::instance()->variable( 'RegionalSettings', 'ContentObjectLocale' );
        $saIni = $this->getSiteAccessIni();
        $siteLanguageList = $saIni->variable( 'RegionalSettings', 'SiteLanguageList' );
        return in_array( $currentContentObjectLocale, $siteLanguageList, true );
    }

    /**
     * Returns URL alias for the specified <var>$locale</var>
     *
     * @param string $url
     * @param string $locale
     * @return void
     */
    public function destinationUrl()
    {
        $nodeId = $this->origUrl;
        if ( !is_numeric( $this->origUrl ) )
        {
            $nodeId = eZURLAliasML::fetchNodeIDByPath( $this->origUrl );
        }

        $saIni = $this->getSiteAccessIni();
        $siteLanguageList = $saIni->variable( 'RegionalSettings', 'SiteLanguageList' );
        if( count( $siteLanguageList ) === 0 ) {
        	$siteLanguageList = array( $saIni->variable( 'RegionalSettings', 'ContentObjectLocale' ) );
        }
        foreach ($siteLanguageList as $siteLanguage)
        {
        	$destinationElement = eZURLAliasML::fetchByAction( 'eznode', $nodeId, $siteLanguage, false );
        	if ( !empty( $destinationElement ) || ( isset( $destinationElement[0] ) && ( $destinationElement[0] instanceof eZURLAliasML ) ) )
        	{
        		break;
        	}
        }

		if(
			count( $saIni->variable( 'RegionalSettings', 'SiteLanguageList' ) ) === 0
			&& count( $destinationElement ) === 0
		) {
			$siteLanguageList   = array( eZINI::instance()->variable( 'RegionalSettings', 'ContentObjectLocale' ) );
			$destinationElement = eZURLAliasML::fetchByAction( 'eznode', $nodeId, true, false );
			if( count( $destinationElement ) > 1 ) {
				$node   = eZContentObjectTreeNode::fetch( $nodeId );
				$object = $node->attribute( 'object' );
				$mask   = $object->attribute( 'initial_language_id' );
/*
				$lang = eZContentLanguage::fetchByLocale( $siteLanguageList[0] );
				$mask = (int) $lang->attribute( 'id' );
*/
				foreach( $destinationElement as $el ) {
					if( ( $mask & (int) $el->attribute( 'lang_mask' ) ) > 0 ) {
						$destinationElement[0] = $el;
						break;
					}
				}
			}
		}

        if ( empty( $destinationElement ) || ( !isset( $destinationElement[0] ) && !( $destinationElement[0] instanceof eZURLAliasML ) ) )
        {
            // If the return of fetchByAction is empty, it can mean a couple
            // of different things:
            // Either we are looking at a module, and we should pass the
            // original URL on
            //
            // Or we are looking at URL which does not exist in the
            // destination siteaccess, for instance an untranslated object. In
            // which case we will point to the root of the site, unless it is
            // available as a fallback.

            if ( $this->isUrlPointingToModule( $this->origUrl ) ||
                 $this->isLocaleAvailableAsFallback() )
            {
                // We have a module, we're keeping the orignal url.
                $urlAlias = $this->origUrl;
            }
            else
            {
                // We probably have an untranslated object, which is not
                // available with SiteLanguageList setting, we direct to root.
                $urlAlias = '';
            }
        }
        else
        {
            // Translated object found, forwarding to new URL.

            $urlAlias = $destinationElement[0]->getPath( $this->destinationLocale, $siteLanguageList );

            // MFH: find any translated canonical urls.
//            $canonicalUrl = Region::findCustomUrlAliases($nodeId);

            $urlAlias .= $this->userParamString;
        }

        $this->baseDestinationUrl = rtrim( $this->baseDestinationUrl, '/' );

        if ( $GLOBALS['eZCurrentAccess']['type'] === eZSiteAccess::TYPE_URI )
        {
            $finalUrl = $this->baseDestinationUrl . '/' . $this->destinationSiteAccess . '/' . $urlAlias;
        }
        else
        {
            $finalUrl = $this->baseDestinationUrl . '/' . $urlAlias;
        }
        return $finalUrl;
    }

    /**
     * Sets the siteaccess name, $saName, we want to redirect to.
     *
     * @param string $saName
     * @return void
     */
    public function setDestinationSiteAccess( $saName )
    {
        $this->destinationSiteAccess = $saName;
    }

    /**
     * This is a hook which is called by the language switcher module on
     * implementation classes.
     *
     * In this implementation it is doing initialisation as an example.
     *
     * @return void
     */
    public function process()
    {
        $saIni = $this->getSiteAccessIni();
        $this->destinationLocale = $saIni->variable( 'RegionalSettings', 'ContentObjectLocale' );

        // Detect the type of siteaccess we are dealing with. Initially URI and Host are supported.
        // We don't want the siteaccess part here, since we are inserting our siteaccess name.
        $indexFile = trim( eZSys::indexFile( false ), '/' );
        switch ( $GLOBALS['eZCurrentAccess']['type'] )
        {
            case eZSiteAccess::TYPE_URI:
                eZURI::transformURI( $host, true, 'full' );
                break;

            default:
                $host = $saIni->variable( 'SiteSettings', 'SiteURL' );
                $host = eZSys::serverProtocol()."://".$host;
                break;
        }
        $this->baseDestinationUrl = "{$host}{$indexFile}";
    }

    /**
     * Creates an array of corresponding language switcher links and logical names.
     *
     * This mapping is set up in site.ini.[RegionalSettings].TranslationSA.
     * The purpose of this method is to assist creation of language switcher
     * links into the available translation siteaccesses on the system.
     *
     * This is used by the language_switcher template operator.
     *
     * @param string $url
     * @return void
     */
    public static function setupTranslationSAList( $url = null )
    {
        $ini = eZINI::instance();
        if ( !$ini->hasVariable( 'RegionalSettings', 'TranslationSA' ) )
        {
            return array();
        }

		$regionIni = eZINI::instance( 'region.ini' );
		$directURL = in_array(
			$regionIni->variable( 'Settings', 'DirectURL' ),
			array( 'yes', 'true', 'enabled' )
		);
        $ret = array();
        $translationSiteAccesses = $ini->variable( 'RegionalSettings', 'TranslationSA' );
        eZDebug::writeDebug($directURL,'directURL');
        foreach ( $translationSiteAccesses as $siteAccessName => $translationName )
        {
        	if( $directURL ) {
				$langSwitch = self::getLangSwitcher();
				$langSwitch->setOrigUrl( $url );
        		$langSwitch->setDestinationSiteAccess( $siteAccessName );
        		$langSwitch->process();
        		$switchLanguageLink = $langSwitch->destinationUrl();
        	} else {
	            $switchLanguageLink = "/switchlanguage/to/{$siteAccessName}/";
	            if ( $url !== null && ( is_string( $url ) || is_numeric( $url ) ) )
	            {
	                $switchLanguageLink .= $url;
	            }
            }
            $ret[$siteAccessName] = array( 'url' => $switchLanguageLink,
                                           'text' => $translationName,
                                           'locale' => eZSiteAccess::getIni( $siteAccessName )->variable( 'RegionalSettings', 'ContentObjectLocale' )
                                         );
        }
        return $ret;
    }

	public static function getLangSwitcher() {
		$handlerOptions = new ezpExtensionOptions();
		$handlerOptions->iniFile = 'site.ini';
		$handlerOptions->iniSection = 'RegionalSettings';
		$handlerOptions->iniVariable = 'LanguageSwitcherClass';
		$handlerOptions->handlerParams = array();
		return eZExtension::getHandlerClass( $handlerOptions );
	}

	public function setOrigUrl( $url ) {
		$this->origUrl = $url;
	}
}

?>