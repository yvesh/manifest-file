<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.pwa
 *
 * @copyright   Copyright (C) 2005 - 2017 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\Registry\Registry;

/**
 * Class PlgSystemPwa
 *
 * @since  __DEPLOY_VERSION__
 */
class PlgSystemPwa extends JPlugin
{
    /**
     * Load the progressive web app and it's web workers
     *
     * @return  void
     *
     * @since  __DEPLOY_VERSION__
     */
    public function onAfterRender()
    {
        // Import service worker plugin group
        JPluginHelper::importPlugin('service-worker');

        // This is an array of service worker files that we need to include
        /** @var string[] $plugins */
        $plugins = JEventDispatcher::getInstance()->trigger('onGetServiceWorkers');

        // Manifest JSON
        $app = JFactory::getApplication();
        $body = $app->getBody();
        if ($app->isSite() == true) {
            $name_of_file = $this->params->get('name_of_file', 'manifest.json');
            $short_name = $this->params->get('short_name', 'Short Name');
            $icon1 = $this->params->get('icon1', 'icon1');
            $icon2 = $this->params->get('icon2', 'icon2');
            $includeserviceworkers = $this->params->get('includeserviceworkers', '0');
            $replacement = '</title>
            <!-- Startup configuration -->';
            if ($start = $this->params->get('start_url', '/')) ;
            $replacement .= '
                <link rel="manifest" href="' . $name_of_file . '">';
            $replacement .= '
                <!-- Fallback application metadata for legacy browsers -->
                <meta name="application-name" content="' . $short_name . '">
                <link rel="icon" sizes="16x16" href="' . $icon1 . '">
                <link rel="icon" sizes="512x512" href="' . $icon2 . '">';

            if ($includeserviceworkers && count($plugins)) {
                // TODO: Loop through the plugins and include each service worker
                $replacement .= '<script>
                    if(\'serviceWorker\' in navigator) {
	                    navigator.serviceWorker
	                    // TODO: This file should come from the $plugin array of files
	                    .register(\'media/plg_service-worker_cache/sw.js\')
	                    .then(function(registration) {
	                    //Registration Worked
		                console.log("Service Worker Registered scope is " + registration.scope);
	                    }).catch(function(error){
	                    // registration failed
                    console.log(\'Registration failed with \' + error);
	                });
                    navigator.serviceWorker.ready.then(function(registration) {
		            console.log("Service Worker Ready");
	            });
            }
            </script>';
            }
            $body = str_replace('</title>', $replacement, $body);
            $app->setBody($body);
        }
    }

    /**
     * Removes or publishes the manifest and service workers if our plugin is disabled or
     * a service plugin is disabled
     *
     * @param   string $context The context of the extension
     * @param   array $pks The ids of the extension who's state is being changed
     * @param   int $value The new value of the state
     */
    public function onContentChangeState($context, $pks, $value)
    {
        if ($context === 'com_plugins.plugin') {
            foreach ($pks as $pk) {
                /** @var JTableExtension $extensionTable */
                $extensionTable = JTable::getInstance('Extension', 'JTable');
                $extensionTable->reset();
                $extensionTable->load($pk);

                if ($extensionTable->name === 'plg_system_pwa' && $value === 1) {
                    $this->buildManifestFile($this->params);
                    $this->createServiceWorkerManifest($this->params);
                } else if ($extensionTable->name === 'plg_system_pwa' && $value === 0) {
                    $this->deleteManifestFile($this->params->get('name_of_file', 'manifest.json'));
                    $this->deleteServerWorkerManifestFile();
                } else if ($extensionTable->folder === 'service-worker') {
                    $this->createServiceWorkerManifest($this->params);
                }
            }
        }
    }

    public function onExtensionAfterSave($context, $table, $isNew)
    {
        if ($context == 'com_plugins.plugin') {
            $result = $table->name;

            if ($result == "plg_system_pwa") {
                $publishedStatus = $table->enabled;
                $newParams = json_decode($table->params, true);

                // Something has gone wrong - we don't have a json object for the params
                if ($newParams == null) {
                    return;
                }

                $newParams = new Registry($newParams);

                if ($publishedStatus == 1) {
                    $this->buildManifestFile($newParams);
                } else {
                    $this->deleteManifestFile($newParams->get('name_of_file', 'manifest.json'));
                    $this->deleteServerWorkerManifestFile();
                }

                $includeserviceworkers = $newParams->get('includeserviceworkers', '0');

                if ($includeserviceworkers && $publishedStatus == 1) {
                    $this->createServiceWorkerManifest($newParams);
                }
            } elseif ($table->folder === 'service-worker') {
                $this->createServiceWorkerManifest($this->params);
            }
        }
    }

    public function onExtensionBeforeUninstall($pk)
    {
        /** @var JTableExtension $extensionTable */
        $extensionTable = JTable::getInstance('Extension', 'JTable');
        $extensionTable->reset();
        $extensionTable->load($pk);

        if ($extensionTable->name === 'plg_system_pwa') {
            $this->deleteManifestFile($this->params->get('name_of_file', 'manifest.json'));
            $this->deleteServerWorkerManifestFile();
        } else if ($extensionTable->folder === 'service-worker') {
            $this->createServiceWorkerManifest($this->params);
        }
    }

    public function onExtensionAfterUpdate($installer, $pk)
    {
        /** @var JTableExtension $extensionTable */
        $extensionTable = JTable::getInstance('Extension', 'JTable');
        $extensionTable->reset();
        $extensionTable->load($pk);

        if ($extensionTable->name === 'plg_system_pwa') {
            $this->buildManifestFile($this->params);
            $this->createServiceWorkerManifest($this->params);
        } else if ($extensionTable->folder === 'service-worker') {
            $this->createServiceWorkerManifest($this->params);
        }
    }

    protected function buildManifestFile(Registry $params)
    {
        $name_of_file = $params->get('name_of_file', 'manifest.json');
        $dir = $params->get('dir', 'ltr');
        $lang = $params->get('lang', 'en');
        $name = $params->get('name');
        $short_name = $params->get('short_name');
        $description = $params->get('description', 'Description of Application');
        $scope = $params->get('scope', '');
        $icons = $params->get('icons', '');
        $display = $params->get('display', 'Standalone');
        $orientation = $params->get('orientation', 'Any');
        $start_url = $params->get('start_url', '/');
        $themecolor = $params->get('themecolor', '#eee');
        $related_applications = $params->get('related_applications', '');
        $prefer_related_applications = $params->get('prefer_related_applications', 'false');
        $backgroundcolor = $params->get('backgroundcolor');

        $commas = 0;
        $manifestContents = '{
    ';
        if ($lang != "") {
            $manifestContents .= '"lang": "' . $lang . '"';
            $commas++;
        }
        if ($dir != "") {
            if ($commas > 0) {
                $manifestContents .= ',
    ';
            }
            $manifestContents .= '"dir": "' . $dir . '"';
            $commas++;
        }
        if ($name != "") {
            if ($commas > 0) {
                $manifestContents .= ',
    ';
            }
            $manifestContents .= '"name": "' . $name . '"';
            $commas++;
        }
        if ($short_name != "") {
            if ($commas > 0) {
                $manifestContents .= ',
    ';
            }
            $manifestContents .= '"short_name": "' . $short_name . '"';
            $commas++;
        }
        if ($description != "") {
            if ($commas > 0) {
                $manifestContents .= ',
    ';
            }
            $manifestContents .= '"description": "' . $description . '"';
            $commas++;
        }
        if ($scope != "") {
            if ($commas > 0) {
                $manifestContents .= ',
    ';
            }
            $manifestContents .= '"scope": "' . $scope . '"';
            $commas++;
        }

        if ($icons != "") {
            if ($commas > 0) {
                $manifestContents .= ',
    ';
            }
            $manifestContents .= '"icons": [
	{
	';
            $iconcount = 0;
            foreach ($icons as $icon) {
                if ($iconcount > 0) {
                    $manifestContents .= '
	},{
	';
                }
                $manifestContents .=
                    '"src": "' . $icon->src . '",
	"sizes": "' . $icon->size[0] . '",
	"type": "' . $icon->type[0] . count($icons) . '"';
                $iconcount++;
            }
            $manifestContents .= '
	}]';
            $commas++;
        }

        if ($display != "") {
            if ($commas > 0) {
                $manifestContents .= ',
    ';
            }
            $manifestContents .= '"display": "' . $display . '"';
            $commas++;
        }
        if ($orientation != "") {
            if ($commas > 0) {
                $manifestContents .= ',
    ';
            }
            $manifestContents .= '"orientation": "' . $orientation . '"';
            $commas++;
        }
        if ($start_url != "") {
            if ($commas > 0) {
                $manifestContents .= ',
    ';
            }
            $manifestContents .= '"start_url": "' . $start_url . '"';
            $commas++;
        }
        if ($themecolor != "") {
            if ($commas > 0) {
                $manifestContents .= ',
    ';
            }
            $manifestContents .= '"theme_color": "' . $themecolor . '"';
            $commas++;
        }
        if ($related_applications != "") {
            if ($commas > 0) {
                $manifestContents .= ',
    ';
            }
            $manifestContents .= '"related_applications": "' . $related_applications . '"';
            $commas++;
        }
        if ($prefer_related_applications != "") {
            if ($commas > 0) {
                $manifestContents .= ',
    ';
            }
            $manifestContents .= '"prefer_related_applications": ' . $prefer_related_applications;
            $commas++;
        }
        if ($backgroundcolor != "") {
            if ($commas > 0) {
                $manifestContents .= ',
    ';
            }
            $manifestContents .= '"background_color": "' . $backgroundcolor . '"';
            $commas++;
        }
        $manifestContents .= '
}';

        // TODO: Use JFile
        $fileManifestWrite = JPATH_ROOT . "/" . $name_of_file;
        $fileManifest = fopen($fileManifestWrite, "w");
        fwrite($fileManifest, $manifestContents);
        fclose($fileManifest);
    }

    protected function deleteManifestFile($fileName)
    {
        JFile::delete(JPATH_ROOT . '/' . $fileName);
    }

    public function createServiceWorkerManifest(Registry $params)
    {
        $root = JURI::root();
        $URI = JURI::getInstance($root);
        $host = $URI->getHost();

        $javascriptContents = 'self.addEventListener(\'install\', function(event) {
    event.waitUntil(
        caches.open(\'' . $host . '\').then(function(cache) {
            return cache.addAll([
                \'' . $params->get('start_url', '/') . '\'
                          ]).then(function() {
                return self.skipWaiting();
            });
        })
    );
});

self.addEventListener(\'activate\', function(event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener(\'fetch\', function(event) {
    console.log(event.request.url);

    event.respondWith(
        caches.match(event.request).then(function(response) {
            return response || fetch(event.request);
        })
    );
});';

        // TODO: Use JFile
        $fileJavascriptWrite = JPATH_ROOT . "/serviceWorker.js";
        $fileJavascript = fopen($fileJavascriptWrite, "w");
        fwrite($fileJavascript, $javascriptContents);
        fclose($fileJavascript);
    }

    protected function deleteServerWorkerManifestFile()
    {
        JFile::delete(JPATH_ROOT . '/serviceWorker.js');
    }
}
