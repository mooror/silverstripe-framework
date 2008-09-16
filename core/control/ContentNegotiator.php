<?php
/**
 * The content negotiator performs "text/html" or "application/xhtml+xml" switching.
 * It does this through the static function ContentNegotiator::process().
 * By default, ContentNegotiator will comply to the Accept headers the clients
 * sends along with the HTTP request, which is most likely "application/xhtml+xml"
 * (see "Order of selection" below).
 * 
 * IMPORTANT: This conversion happens by default to all template output unless
 * explicitly disabled through ContentNegotiator::disable().
 *
 * Order of selection between html or xhtml is as follows:
 * - if PHP has already sent the HTTP headers, default to "html" (we can't send HTTP Content-Type headers any longer)
 * - if a GET variable ?forceFormat is set, it takes precedence (for testing purposes)
 * - if the user agent is detected as W3C Validator we always deliver "xhtml"
 * - if an HTTP Accept header is sent from the client, we respect its order (this is the most common case)
 * - if none of the above matches, fallback is "html"
 * 
 * ContentNegotiator doesn't enable you to send content as a true XML document
 * through the "text/xml" or "application/xhtml+xml" Content-Type.
 * Please see http://webkit.org/blog/68/understanding-html-xml-and-xhtml/ for further information.
 * 
 * @package sapphire
 * @subpackage control
 * @see http://doc.silverstripe.com/doku.php?id=xhtml-support
 * @see http://doc.silverstripe.com/doku.php?id=contentnegotiator
 * @see http://doc.silverstripe.com/doku.php?id=html
 * 
 * @todo Check for correct XHTML doctype in xhtml()
 * @todo Allow for other HTML4 doctypes (e.g. Transitional) in html()
 * @todo Make content replacement and doctype setting two separately configurable behaviours - some
 * devs might know what they're doing and don't want contentnegotiator messing with their HTML4 doctypes,
 * but still find it useful to have self-closing tags removed.
 */
class ContentNegotiator {
	protected static $encoding = 'utf-8';
	
	/**
	 * Set the character set encoding for this page.  By default it's utf-8, but you could change it to, say, windows-1252, to
	 * improve interoperability with extended characters being imported from windows excel.
	 */
	static function set_encoding($encoding) {
		self::$encoding = $encoding;
	}
	
	/**
	 * Return the character encoding set bhy ContentNegotiator::set_encoding().  It's recommended that all classes that need to
	 * specify the character set make use of this function.
	 */
	static function get_encoding() {
	    return self::$encoding;
	}
	
	/**
	 * @usedby Controller->handleRequest()
	 */
	static function process(HTTPResponse $response) {
		if(self::$disabled) return;

		$mimes = array(
			"xhtml" => "application/xhtml+xml",
			"html" => "text/html",
		);
		$q = array();
		if(headers_sent()) {
			$chosenFormat = "html";

		} else if(isset($_GET['forceFormat'])) {
			$chosenFormat = $_GET['forceFormat'];

		} else {
			// The W3C validator doesn't send an HTTP_ACCEPT header, but it can support xhtml.  We put this special case in here so that
			// designers don't get worried that their templates are HTML4.
 			if(isset($_SERVER['HTTP_USER_AGENT']) && substr($_SERVER['HTTP_USER_AGENT'], 0, 14) == 'W3C_Validator/') {
				$chosenFormat = "xhtml";
	
			} else {
				foreach($mimes as $format => $mime) {
					$regExp = '/' . str_replace(array('+','/'),array('\+','\/'), $mime) . '(;q=(\d+\.\d+))?/i';
					if (isset($_SERVER['HTTP_ACCEPT']) && preg_match($regExp, $_SERVER['HTTP_ACCEPT'], $matches)) {
						$preference = isset($matches[2]) ? $matches[2] : 1;
						if(!isset($q[$preference])) $q[$preference] = $format;
					}
				}

				if($q) {
					// Get the preferred format
					krsort($q);
					$chosenFormat = reset($q);
				} else {
					$chosenFormat = "html";
				}
			}
		}

		$negotiator = new ContentNegotiator();
		$negotiator->$chosenFormat( $response );
	}

	/**
	 * Only sends the HTTP Content-Type as "application/xhtml+xml"
	 * if the template starts with the typical "<?xml" Pragma.
	 * Assumes that a correct doctype is set, and doesn't change or append to it.
	 * Replaces a few common tags and entities with their XHTML representations (<br>, <img>, &nbsp;).
	 *
	 * @param $response HTTPResponse
	 * @return string
	 * @todo More flexible tag and entity parsing through regular expressions or tag definition lists
	 */
	function xhtml(HTTPResponse $response) {
		$content = $response->getBody();
		
		// Only serve "pure" XHTML if the XML header is present
		if(substr($content,0,5) == '<' . '?xml' ) {
			$response->addHeader("Content-type", "application/xhtml+xml; charset=" . self::$encoding);
			$response->addHeader("Vary" , "Accept");
			
			$content = str_replace('&nbsp;','&#160;', $content);
			$content = str_replace('<br>','<br />', $content);
			$content = eregi_replace('(<img[^>]*[^/>])>','\\1/>', $content);
			
			$response->setBody($content);

		} else {
			return $this->html($response);
		}
	}
	
	/*
	 * Sends HTTP Content-Type as "text/html", and replaces existing doctypes with
	 * HTML4.01 Strict.
	 * Replaces self-closing tags like <img /> with unclosed solitary tags like <img>.
	 * Replaces all occurrences of "application/xhtml+xml" with "text/html" in the template.
	 * Removes "xmlns" attributes and any <?xml> Pragmas.
	 */
	function html(HTTPResponse $response) {
		$response->addHeader("Content-type", "text/html; charset=" . self::$encoding);
		$response->addHeader("Vary", "Accept");

		$content = $response->getBody();

		$content = ereg_replace("<\\?xml[^>]+\\?>\n?",'',$content);
		$content = str_replace(array('/>','xml:lang','application/xhtml+xml'),array('>','lang','text/html'), $content);
		$content = ereg_replace('<!DOCTYPE[^>]+>', '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">', $content);
		$content = ereg_replace('<html xmlns="[^"]+"','<html ', $content);
		
		$response->setBody($content);
	}

	protected static $disabled;
	static function disable() {
		self::$disabled = true;
	}
}

?>
