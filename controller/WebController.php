<?php

/**
 * Importing the dependencies.
 */
use \Punic\Language;

/**
 * WebController is an extension of the Controller that handles all
 * the requests originating from the view of the website.
 */
class WebController extends Controller
{
    /**
     * Provides access to the templating engine.
     * @property object $twig the twig templating engine.
     */
    public $twig;

    /**
     * Constructor for the WebController.
     * @param Model $model
     */
    public function __construct($model)
    {
        parent::__construct($model);

        // initialize Twig templates
        $tmpDir = $model->getConfig()->getTemplateCache();

        // check if the cache pointed by config.inc exists, if not we create it.
        if (!file_exists($tmpDir)) {
            mkdir($tmpDir);
        }

        // specify where to look for templates and cache
        $loader = new Twig_Loader_Filesystem('view');
        // initialize Twig environment
        $this->twig = new Twig_Environment($loader, array('cache' => $tmpDir,
            'auto_reload' => true, 'debug' => true));
        $this->twig->addExtension(new Twig_Extensions_Extension_I18n());
        //ENABLES DUMP() method for easy and fun debugging!
        $this->twig->addExtension(new Twig_Extension_Debug());
        // used for setting the base href for the relative urls
        $this->twig->addGlobal("BaseHref", $this->getBaseHref());
        // setting the service name string from the config.inc
        $this->twig->addGlobal("ServiceName", $this->model->getConfig()->getServiceName());
        // setting the service logo location from the config.inc
        if ($this->model->getConfig()->getServiceLogo() !== null) {
            $this->twig->addGlobal("ServiceLogo", $this->model->getConfig()->getServiceLogo());
        }

        // setting the service custom css file from the config.inc
        if ($this->model->getConfig()->getCustomCss() !== null) {
            $this->twig->addGlobal("ServiceCustomCss", $this->model->getConfig()->getCustomCss());
        }
        // used for displaying the ui language selection as a dropdown
        if ($this->model->getConfig()->getUiLanguageDropdown() !== null) {
            $this->twig->addGlobal("LanguageDropdown", $this->model->getConfig()->getUiLanguageDropdown());
        }

        // setting the list of properties to be displayed in the search results
        $this->twig->addGlobal("PreferredProperties", array('skos:prefLabel', 'skos:narrower', 'skos:broader', 'skosmos:memberOf', 'skos:altLabel', 'skos:related'));

        // register a Twig filter for generating URLs for vocabulary resources (concepts and groups)
        $controller = $this; // for use by anonymous function below
        $urlFilter = new Twig_SimpleFilter('link_url', function ($uri, $vocab, $lang, $type = 'page', $clang = null, $term = null) use ($controller) {
            // $vocab can either be null, a vocabulary id (string) or a Vocabulary object
            if ($vocab === null) {
                // target vocabulary is unknown, best bet is to link to the plain URI
                return $uri;
            } elseif (is_string($vocab)) {
                $vocid = $vocab;
                $vocab = $controller->model->getVocabulary($vocid);
            } else {
                $vocid = $vocab->getId();
            }

            $params = array();
            if (isset($clang) && $clang !== $lang) {
                $params['clang'] = $clang;
            }

            if (isset($term)) {
                $params['q'] = $term;
            }

            // case 1: URI within vocabulary namespace: use only local name
            $localname = $vocab->getLocalName($uri);
            if ($localname !== $uri && $localname === urlencode($localname)) {
                // check that the prefix stripping worked, and there are no problematic chars in localname
                $paramstr = sizeof($params) > 0 ? '?' . http_build_query($params) : '';
                if ($type && $type !== '' && $type !== 'vocab' && !($localname === '' && $type === 'page')) {
                    return "$vocid/$lang/$type/$localname" . $paramstr;
                }

                return "$vocid/$lang/$localname" . $paramstr;
            }

            // case 2: URI outside vocabulary namespace, or has problematic chars
            // pass the full URI as parameter instead
            $params['uri'] = $uri;
            return "$vocid/$lang/$type/?" . http_build_query($params);
        });
        $this->twig->addFilter($urlFilter);

        // register a Twig filter for generating strings from language codes with CLDR
        $langFilter = new Twig_SimpleFilter('lang_name', function ($langcode, $lang) {
            return Language::getName($langcode, $lang);
        });
        $this->twig->addFilter($langFilter);

        // create the honeypot
        $this->honeypot = new \Honeypot();
        if (!$this->model->getConfig()->getHoneypotEnabled()) {
            $this->honeypot->disable();
        }
        $this->twig->addGlobal('honeypot', $this->honeypot);
    }

    /**
     * Guess the language of the user. Return a language string that is one
     * of the supported languages defined in the $LANGUAGES setting, e.g. "fi".
     * @param string $vocid identifier for the vocabulary eg. 'yso'.
     * @return string returns the language choice as a numeric string value
     */
    public function guessLanguage($vocid = null)
    {
        // 1. select language based on SKOSMOS_LANGUAGE cookie
        if (filter_input(INPUT_COOKIE, 'SKOSMOS_LANGUAGE', FILTER_SANITIZE_STRING)) {
            return filter_input(INPUT_COOKIE, 'SKOSMOS_LANGUAGE', FILTER_SANITIZE_STRING);
        }

        // 2. if vocabulary given, select based on the default language of the vocabulary
        if ($vocid !== null && $vocid !== '') {
            try {
                $vocab = $this->model->getVocabulary($vocid);
                return $vocab->getConfig()->getDefaultLanguage();
            } catch (Exception $e) {
                // vocabulary id not found, move on to the next selection method
            }
        }

        // 3. select language based on Accept-Language header
        header('Vary: Accept-Language'); // inform caches that a decision was made based on Accept header
        $this->negotiator = new \Negotiation\LanguageNegotiator();
        $langcodes = array_keys($this->languages);
        $acceptLanguage = filter_input(INPUT_SERVER, 'HTTP_ACCEPT_LANGUAGE', FILTER_SANITIZE_STRING) ? filter_input(INPUT_SERVER, 'HTTP_ACCEPT_LANGUAGE', FILTER_SANITIZE_STRING) : '';
        $bestLang = $this->negotiator->getBest($acceptLanguage, $langcodes);
        if (isset($bestLang) && in_array($bestLang, $langcodes)) {
            return $bestLang->getValue();
        }

        // show default site or prompt for language
        return $langcodes[0];
    }

    /**
     * Determines a css class that controls width and positioning of the vocabulary list element. 
     * The layout is wider if the left/right box templates have not been provided.
     * @return string css class for the container eg. 'voclist-wide' or 'voclist-right'
     */
    private function listStyle() {
        $left = file_exists('view/left.inc');
        $right = file_exists('view/right.inc');
        $ret = 'voclist';
        if (!$left && !$right) {
            $ret .= '-wide';
        } else if (!($left && $right) && ($right || $left)) {
            $ret .= ($right) ? '-left' : '-right';
        }
        return $ret;
    }

    /**
     * Loads and renders the view containing all the vocabularies.
     * @param Request $request
     */
    public function invokeVocabularies($request)
    {
        // set language parameters for gettext
        $this->setLanguageProperties($request->getLang());
        // load template
        $template = $this->twig->loadTemplate('light.twig');
        // set template variables
        $categoryLabel = $this->model->getClassificationLabel($request->getLang());
        $sortedVocabs = $this->model->getVocabularyList(false, true);
        $langList = $this->model->getLanguages($request->getLang());
        $listStyle = $this->listStyle(); 

        // render template
        echo $template->render(
            array(
                'sorted_vocabs' => $sortedVocabs,
                'category_label' => $categoryLabel,
                'languages' => $this->languages,
                'lang_list' => $langList,
                'request' => $request,
                'list_style' => $listStyle
            ));
    }

    /**
     * Invokes the concept page of a single concept in a specific vocabulary.
     */
    public function invokeVocabularyConcept($request)
    {
        $lang = $request->getLang();
        $this->setLanguageProperties($lang);
        $vocab = $request->getVocab();

        $langcodes = $vocab->getConfig()->getShowLangCodes();
        $uri = $vocab->getConceptURI($request->getUri()); // make sure it's a full URI

        $results = $vocab->getConceptInfo($uri, $request->getContentLang());
        if (!$results) {
            $this->invokeGenericErrorPage($request);
            return;
        }
        $template = (in_array('skos:Concept', $results[0]->getType()) || in_array('skos:ConceptScheme', $results[0]->getType())) ? $this->twig->loadTemplate('concept-info.twig') : $this->twig->loadTemplate('group-contents.twig');
        
        $crumbs = $vocab->getBreadCrumbs($request->getContentLang(), $uri);
        echo $template->render(array(
            'search_results' => $results,
            'vocab' => $vocab,
            'languages' => $this->languages,
            'explicit_langcodes' => $langcodes,
            'bread_crumbs' => $crumbs['breadcrumbs'],
            'combined' => $crumbs['combined'],
            'request' => $request)
        );
    }

    /**
     * Invokes the feedback page with information of the users current vocabulary.
     */
    public function invokeFeedbackForm($request)
    {
        $template = $this->twig->loadTemplate('feedback.twig');
        $this->setLanguageProperties($request->getLang());
        $vocabList = $this->model->getVocabularyList(false);
        $vocab = $request->getVocab();

        $feedbackSent = false;
        $feedbackMsg = null;
        if ($request->getQueryParamPOST('message')) {
            $feedbackSent = true;
            $feedbackMsg = $request->getQueryParamPOST('message');
        }
        $feedbackName = $request->getQueryParamPOST('name');
        $feedbackEmail = $request->getQueryParamPOST('email');
        $feedbackVocab = $request->getQueryParamPOST('vocab');
        $feedbackVocabEmail = ($vocab !== null) ? $vocab->getConfig()->getFeedbackRecipient() : null;

        // if the hidden field has been set a value we have found a spam bot
        // and we do not actually send the message.
        if ($this->honeypot->validateHoneypot($request->getQueryParamPOST('item-description')) &&
            $this->honeypot->validateHoneytime($request->getQueryParamPOST('user-captcha'), $this->model->getConfig()->getHoneypotTime())) {
            $this->sendFeedback($request, $feedbackMsg, $feedbackName, $feedbackEmail, $feedbackVocab, $feedbackVocabEmail);
        }

        echo $template->render(
            array(
                'languages' => $this->languages,
                'vocab' => $vocab,
                'vocabList' => $vocabList,
                'feedback_sent' => $feedbackSent,
                'request' => $request,
            ));
    }

    private function createFeedbackHeaders($fromName, $fromEmail, $toMail)
    {
        $headers = "MIME-Version: 1.0″ . '\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8" . "\r\n";
        if ($toMail) {
            $headers .= "Cc: " . $this->model->getConfig()->getFeedbackAddress() . "\r\n";
        }

        $headers .= "From: $fromName <$fromEmail>" . "\r\n" . 'X-Mailer: PHP/' . phpversion();
        return $headers;
    }

    /**
     * Sends the user entered message through the php's mailer.
     * @param string $message only required parameter is the actual message.
     * @param string $fromName senders own name.
     * @param string $fromEmail senders email adress.
     * @param string $fromVocab which vocabulary is the feedback related to.
     */
    public function sendFeedback($request, $message, $fromName = null, $fromEmail = null, $fromVocab = null, $toMail = null)
    {
        $toAddress = ($toMail) ? $toMail : $this->model->getConfig()->getFeedbackAddress();
        if ($fromVocab && $fromVocab !== '') {
            $message = 'Feedback from vocab: ' . strtoupper($fromVocab) . "<br />" . $message;
        }

        $subject = SERVICE_NAME . " feedback";
        $headers = $this->createFeedbackHeaders($fromName, $fromEmail, $toMail);
        $envelopeSender = FEEDBACK_ENVELOPE_SENDER;
        $params = empty($envelopeSender) ? '' : "-f $envelopeSender";

        // adding some information about the user for debugging purposes.
        $message = $message . "<br /><br /> Debugging information:"
            . "<br />Timestamp: " . date(DATE_RFC2822)
            . "<br />User agent: " . $request->getServerConstant('HTTP_USER_AGENT')
            . "<br />IP address: " . $request->getServerConstant('REMOTE_ADDR')
            . "<br />Referer: " . $request->getServerConstant('HTTP_REFERER');

        try {
            mail($toAddress, $subject, $message, $headers, $params);
        } catch (Exception $e) {
            header("HTTP/1.0 404 Not Found");
            $template = $this->twig->loadTemplate('error-page.twig');
            if ($this->model->getConfig()->getLogCaughtExceptions()) {
                error_log('Caught exception: ' . $e->getMessage());
            }

            echo $template->render(
                array(
                    'languages' => $this->languages,
                ));

            return;
        }
    }

    /**
     * Invokes the about page for the Skosmos service.
     */
    public function invokeAboutPage($request)
    {
        $template = $this->twig->loadTemplate('about.twig');
        $this->setLanguageProperties($request->getLang());
        $url = $request->getServerConstant('HTTP_HOST');
        $version = $this->model->getVersion();

        echo $template->render(
            array(
                'languages' => $this->languages,
                'version' => $version,
                'server_instance' => $url,
                'request' => $request,
            ));
    }

    /**
     * Invokes the search for concepts in all the availible ontologies.
     */
    public function invokeGlobalSearch($request)
    {
        $lang = $request->getLang();
        $template = $this->twig->loadTemplate('vocab-search-listing.twig');
        $this->setLanguageProperties($lang);

        $parameters = new ConceptSearchParameters($request, $this->model->getConfig());

        $vocabs = $request->getQueryParam('vocabs'); # optional
        // convert to vocids array to support multi-vocabulary search
        $vocids = ($vocabs !== null && $vocabs !== '') ? explode(' ', $vocabs) : null;
        $vocabObjects = array();
        if ($vocids) {
            foreach($vocids as $vocid) {
                $vocabObjects[] = $this->model->getVocabulary($vocid);
            }
        }
        $parameters->setVocabularies($vocabObjects);

        try {
            $countAndResults = $this->model->searchConceptsAndInfo($parameters);
        } catch (Exception $e) {
            header("HTTP/1.0 404 Not Found");
            if ($this->model->getConfig()->getLogCaughtExceptions()) {
                error_log('Caught exception: ' . $e->getMessage());
            }
            $this->invokeGenericErrorPage($request, $e->getMessage());
            return;
        }
        $counts = $countAndResults['count'];
        $searchResults = $countAndResults['results'];
        $vocabList = $this->model->getVocabularyList();
        $sortedVocabs = $this->model->getVocabularyList(false, true);
        $langList = $this->model->getLanguages($lang);

        echo $template->render(
            array(
                'search_count' => $counts,
                'languages' => $this->languages,
                'search_results' => $searchResults,
                'rest' => $parameters->getOffset()>0,
                'global_search' => true,
                'term' => $request->getQueryParam('q'),
                'lang_list' => $langList,
                'vocabs' => str_replace(' ', '+', $vocabs),
                'vocab_list' => $vocabList,
                'sorted_vocabs' => $sortedVocabs,
                'request' => $request,
                'parameters' => $parameters
            ));
    }

    /**
     * Invokes the search for a single vocabs concepts.
     */
    public function invokeVocabularySearch($request)
    {
        $template = $this->twig->loadTemplate('vocab-search-listing.twig');
        $this->setLanguageProperties($request->getLang());
        $vocab = $request->getVocab();
        try {
            $vocabTypes = $this->model->getTypes($request->getVocabid(), $request->getLang());
        } catch (Exception $e) {
            header("HTTP/1.0 404 Not Found");
            if ($this->model->getConfig()->getLogCaughtExceptions()) {
                error_log('Caught exception: ' . $e->getMessage());
            }

            echo $template->render(
                array(
                    'languages' => $this->languages,
                ));

            return;
        }

        $langcodes = $vocab->getConfig()->getShowLangCodes();
        $parameters = new ConceptSearchParameters($request, $this->model->getConfig());

        try {
            $countAndResults = $this->model->searchConceptsAndInfo($parameters);
            $counts = $countAndResults['count'];
            $searchResults = $countAndResults['results'];
        } catch (Exception $e) {
            header("HTTP/1.0 404 Not Found");
            if ($this->model->getConfig()->getLogCaughtExceptions()) {
                error_log('Caught exception: ' . $e->getMessage());
            }

            echo $template->render(
                array(
                    'languages' => $this->languages,
                    'vocab' => $vocab,
                    'term' => $request->getQueryParam('q'),
                ));
            return;
        }
        echo $template->render(
            array(
                'languages' => $this->languages,
                'vocab' => $vocab,
                'search_results' => $searchResults,
                'search_count' => $counts,
                'rest' => $parameters->getOffset()>0,
                'limit_parent' => $parameters->getParentLimit(),
                'limit_type' =>  $request->getQueryParam('type') ? explode('+', $request->getQueryParam('type')) : null,
                'limit_group' => $parameters->getGroupLimit(),
                'limit_scheme' =>  $request->getQueryParam('scheme') ? explode('+', $request->getQueryParam('scheme')) : null,
                'group_index' => $vocab->listConceptGroups($request->getContentLang()),
                'parameters' => $parameters,
                'term' => $request->getQueryParam('q'),
                'types' => $vocabTypes,
                'explicit_langcodes' => $langcodes,
                'request' => $request,
            ));
    }

    /**
     * Invokes the alphabetical listing for a specific vocabulary.
     */
    public function invokeAlphabeticalIndex($request)
    {
        $lang = $request->getLang();
        $this->setLanguageProperties($lang);
        $template = $this->twig->loadTemplate('alphabetical-index.twig');
        $vocab = $request->getVocab();

        $offset = ($request->getQueryParam('offset') && is_numeric($request->getQueryParam('offset')) && $request->getQueryParam('offset') >= 0) ? $request->getQueryParam('offset') : 0;
        if ($request->getQueryParam('limit')) {
            $count = $request->getQueryParam('limit');
        } else {
            $count = ($offset > 0) ? null : 250;
        }

        $contentLang = $request->getContentLang();

        $allAtOnce = $vocab->getConfig()->getAlphabeticalFull();
        if (!$allAtOnce) {
            $searchResults = $vocab->searchConceptsAlphabetical($request->getLetter(), $count, $offset, $contentLang);
            $letters = $vocab->getAlphabet($contentLang);
        } else {
            $searchResults = $vocab->searchConceptsAlphabetical('*', null, null, $contentLang);
            $letters = null;
        }

        $request->setContentLang($contentLang);

        echo $template->render(
            array(
                'languages' => $this->languages,
                'vocab' => $vocab,
                'alpha_results' => $searchResults,
                'letters' => $letters,
                'all_letters' => $allAtOnce,
                'request' => $request,
            ));
    }

    /**
     * Invokes the vocabulary group index page template.
     * @param boolean $stats set to true to get vocabulary statistics visible.
     */
    public function invokeGroupIndex($request, $stats = false)
    {
        $lang = $request->getLang();
        $this->setLanguageProperties($lang);
        $template = $this->twig->loadTemplate('group-index.twig');
        $vocab = $request->getVocab();

        echo $template->render(
            array(
                'languages' => $this->languages,
                'stats' => $stats,
                'vocab' => $vocab,
                'request' => $request,
            ));
    }

    /**
     * Loads and renders the view containing a specific vocabulary.
     */
    public function invokeVocabularyHome($request)
    {
        $lang = $request->getLang();
        // set language parameters for gettext
        $this->setLanguageProperties($lang);
        $vocab = $request->getVocab();

        $defaultView = $vocab->getConfig()->getDefaultSidebarView();
        // load template
        if ($defaultView === 'groups') {
            $this->invokeGroupIndex($request, true);
            return;
        }

        $template = $this->twig->loadTemplate('vocab.twig');

        echo $template->render(
            array(
                'languages' => $this->languages,
                'vocab' => $vocab,
                'search_letter' => 'A',
                'active_tab' => $defaultView,
                'request' => $request,
            ));
    }

    /**
     * Invokes a very generic errorpage.
     */
    public function invokeGenericErrorPage($request, $message = null)
    {
        $this->setLanguageProperties($request->getLang());
        header("HTTP/1.0 404 Not Found");
        $template = $this->twig->loadTemplate('error-page.twig');
        echo $template->render(
            array(
                'languages' => $this->languages,
                'request' => $request,
                'message' => $message,
                'requested_page' => filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_STRING),
            ));
    }

    /**
     * Loads and renders the view containing a list of recent changes in the vocabulary.
     * @param Request $request
     */
    public function invokeChangeList($request, $prop='dc:created')
    {
        // set language parameters for gettext
        $this->setLanguageProperties($request->getLang());
        $vocab = $request->getVocab();
        $offset = ($request->getQueryParam('offset') && is_numeric($request->getQueryParam('offset')) && $request->getQueryParam('offset') >= 0) ? $request->getQueryParam('offset') : 0;
        $changeList = $vocab->getChangeList($prop, $request->getContentLang(), $request->getLang(), $offset);
        // load template
        $template = $this->twig->loadTemplate('changes.twig');

        // render template
        echo $template->render(
            array(
                'vocab' => $vocab,
                'languages' => $this->languages,
                'request' => $request,
                'changeList' => $changeList)
            );
    }

}
