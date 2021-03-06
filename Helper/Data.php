<?php
/**
 * Copyright © 2016 Sectionio. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Sectionio\Metrics\Helper;

use Magento\Framework\App\Helper\AbstractHelper;

class Data extends AbstractHelper
{
    /** @var \Sectionio\Metrics\Model\SettingsFactory $settingsFactory */
    protected $settingsFactory;
    /** @var \Sectionio\Metrics\Model\AccountFactory $accountFactory */
    protected $accountFactory;
    /** @var \Sectionio\Metrics\Model\ApplicationFactory $applicationFactory */
    protected $applicationFactory;
    /** @var \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig */
    protected $scopeConfig;
    /** @var \Magento\Framework\Encryption\EncryptorInterface $encryptor */
    protected $encryptor;
    // var \Magento\Framework\Filesystem\DirectoryList $directoryList
    protected $directoryList;
    // var \Magento\Store\Model\StoreManagerInterface $storeManager
    protected $storeManager;

    /**
     * @param \Magento\Framework\App\Helper\Context $context
     * @param \Sectionio\Metrics\Model\SettingsFactory $settingsFactory
     * @param \Sectionio\Metrics\Model\AccountFactory $accountFactory
     * @param \Sectionio\Metrics\Model\ApplicationFactory $applicationFactory
     * @param \Magento\Framework\Encryption\EncryptorInterface $encryptor
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Sectionio\Metrics\Model\SettingsFactory $settingsFactory,
        \Sectionio\Metrics\Model\AccountFactory $accountFactory,
        \Sectionio\Metrics\Model\ApplicationFactory $applicationFactory,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Magento\Framework\Filesystem\DirectoryList $directoryList,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->settingsFactory = $settingsFactory;
        $this->accountFactory = $accountFactory;
        $this->applicationFactory = $applicationFactory;
        $this->scopeConfig = $context->getScopeConfig();
        $this->encryptor = $encryptor;
        $this->directoryList = $directoryList;
        $this->storeManager = $storeManager;
    }

    private function getPluginConfig() {
        $cache_expiry_seconds = 60 * 60; // 1 hour

        // http://blog.belvg.com/how-to-get-access-to-working-directories-in-magento-2-0.html
        $cache_dir = $this->directoryList->getPath('cache') . '/section.io';
        if (!is_dir($cache_dir)) {
            try {
                mkdir($cache_dir);
            } catch (\Exception $e) {
                $this->_logger->warning('Could not create cache directory. Skipping cache.', [ 'exception' => $e ]);
            }
        }
        $cached_config_file = $cache_dir . '/magento-section-io-plugin-config.json';

        if (is_file($cached_config_file)) {
            $mtime = filemtime($cached_config_file);
            if (time() - $mtime < $cache_expiry_seconds) {
                return file_get_contents($cached_config_file);
            }
        }

        /** @var string $service_url */
        $service_url = 'https://www.section.io/magento-section-io-plugin-config.json';

        // setup curl call
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $service_url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        // if response received
        if ($curl_response = curl_exec($ch)) {
            try {
                file_put_contents($cached_config_file, $curl_response);
            } catch (\Exception $e) {
                $this->_logger->warning('Could not write cache file. Skipping cache.', [ 'exception' => $e ]);
            }
            return $curl_response;
        }
    }

    // to find all the identifiers used with GetCopy, use:
    // $ grep -ho "getCopy([\"']\([^\"']\|\\[\"']\)*" --include=*.php -r /var/www/html/app/code/Sectionio/Metrics | cut -c10-
    public function getCopy($identifier, $default) {
        $decoded = json_decode($this->getPluginConfig(), true);
        $copy = [];
        if (array_key_exists('copy', $decoded)) {
            $copy = $decoded['copy'];
        }
        if (array_key_exists($identifier, $copy)) {
            return $copy[$identifier];
        }
        return $default;
    }

    /**
     * Retrieves the section.io site metrics
     *
     * @param int $account_id
     * @param int $application_id
     *
     * @return array()
     */
    public function getMetrics($account_id, $application_id) {

        /** @var array() $response */
        $response = [];
        /** @var int $count */
        $count = 0;

        // if response received
        if ($plugin_config = $this->getPluginConfig()) {
            if ($data = json_decode ($plugin_config, true)) {
                // loop through return data
                foreach ($data as $key => $charts) {
                    if (is_array ($charts)) {
                        // loop through each chart / graph
                        foreach ($charts as $chart) {
                            // make sure return data exists
                            if (isset ($chart['url']) && isset ($chart['title'])) {
                                /** @var string $url */
                                $url = str_replace ('https://aperture.section.io/account/1/application/1/', '', $chart['url']);
                                /** @var string $service_url */
                                $service_url = ('https://aperture.section.io/account/' . $account_id . '/application/' . $application_id . '/' . $url);
                                // append time zone
                                $service_url .= '&tz=' . $this->scopeConfig->getValue('general/locale/timezone', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
                                /** @var object $image */
                                if ($image = $this->performCurl($service_url)['body_content']) {
                                    // build return array
                                    $response[$count]['title'] = $chart['title'];
                                    $response[$count]['chart'] = base64_encode ($image);
                                    $response[$count]['help'] = $chart['help'];
                                    $response[$count]['docs'] = $chart['docs'];
		                            if (isset ($chart['apertureLink'])) {
		                                $response[$count]['apertureLink'] = $chart['apertureLink'];
		                            }
                                    // increment count
                                    $count ++;
                                }
                            }
                        }
                    }
                    elseif ($key == 'intro') {
                        if (is_string ($charts)) {
                            $response['intro'] = $charts;
                        }
                    }
                }
            }
        }
        return $response;
    }

    /**
     * Save the user's password encrypted in the database
     *
     * @param string $password
     *
     */
    public function savePassword ($settingsFactory, $password) {
        $settingsFactory->setData('password', $this->encryptor->encrypt($password));
    }

    /**
     * Generate an aperture API URL
     *
     * @param array $parameters
     *
     */
    public function generateApertureUrl ($parameters) {
        $url = 'https://aperture.section.io/api/v1';
        if (isset($parameters['accountId'])) {
            $url .= '/account/' . $parameters['accountId'];
        }

        if (isset($parameters['applicationId'])) {
            $url .= '/application/' . $parameters['applicationId'];
        }

        if (isset($parameters['environmentName'])) {
            $url .= '/environment/' . $parameters['environmentName'];
        }

        if (isset($parameters['proxyName'])) {
            $url .= '/proxy/' . $parameters['proxyName'];
        }

        if (isset($parameters['domain'])) {
            $url .= '/domain/' . $parameters['domain'];
        }

        if (isset($parameters['uriStem'])) {
            $url .= $parameters['uriStem'];
        }
        $this->_logger->debug($url);
        return $url;
    }

    /**
     * Perform Sectionio curl call
     *
     * @param string $service_url
     * @param array() $credentials
     * @param string $method
     * @param array() $payload
     *
     * @return array() $response
     */
    public function performCurl ($service_url, $method = 'GET', $payload = null) {

        /** @var \Sectionio\Metrics\Model\SettingsFactory $settingsFactory */
        $settingsFactory = $this->settingsFactory->create()->getCollection()->getFirstItem();
        /** @var string $credentials */
        $credentials = ($settingsFactory->getData('user_name') . ':' . $this->encryptor->decrypt($settingsFactory->getData('password')));

        // setup curl call
         $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $service_url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_USERPWD, $credentials);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            /** @var string $json */
            $json = json_encode($payload);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($json)]);
        }

        // construct the response object from the curl info and the body response
        $curl_response = curl_exec($ch);
        $curl_info = curl_getinfo($ch);
        $curl_info['body_content'] = $curl_response;

        return $curl_info;
    }

}
