<?php
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) 2000-2015 LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */


namespace Eccube\Service;

use Eccube\Common\Constant;
use Eccube\Exception\PluginException;
use Eccube\Util\Str;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

class PluginService
{
    private $app;

    CONST CONFIG_YML = 'config.yml';
    CONST EVENT_YML = 'event.yml';

    public function __construct($app)
    {
        $this->app = $app;
    }

    public function install($path, $source = 0)
    {
        try {

            $tmp = $this->createTempDir();

            $this->unpackPluginArchive($path, $tmp); //一旦テンポラリに展開
            $this->checkPluginArchiveContent($tmp);

            $config = $this->readYml($tmp . '/' . self::CONFIG_YML);
            $event = $this->readYml($tmp . '/' . self::EVENT_YML);
            $this->deleteFile($tmp); // テンポラリのファイルを削除

            $this->checkSamePlugin($config['code']); // 重複していないかチェック

            $pluginBaseDir = $this->calcPluginDir($config['code']);
            $this->createPluginDir($pluginBaseDir); // 本来の置き場所を作成

            $this->unpackPluginArchive($path, $pluginBaseDir); // 問題なければ本当のplugindirへ

            $this->registerPlugin($config, $event, $source); // dbにプラグイン登録
            $this->callPluginManagerMethod($config, 'install');
            $this->callPluginManagerMethod($config, 'enable');

        } catch (PluginException $e) {
            if (file_exists($tmp)) {
                $fs = new Filesystem();
                $fs->remove($tmp);
            }
            throw $e;
        }

        return true;
    }

    public function uninstall(\Eccube\Entity\Plugin $plugin)
    {

        $pluginDir = $this->calcPluginDir($plugin->getCode());

        $this->callPluginManagerMethod(Yaml::Parse($pluginDir . '/' . self::CONFIG_YML), 'disable');
        $this->callPluginManagerMethod(Yaml::Parse($pluginDir . '/' . self::CONFIG_YML), 'uninstall');
        $this->unregisterPlugin($plugin);
        $this->deleteFile($pluginDir);

        return true;

    }

    public function enable(\Eccube\Entity\Plugin $plugin, $enable = true)
    {
        $pluginDir = $this->calcPluginDir($plugin->getCode());
        $em = $this->app['orm.em'];
        $plugin->setEnable($enable ? Constant::ENABLED : Constant::DISABLED);
        $em->persist($plugin);
        $em->flush();
        $this->callPluginManagerMethod(Yaml::Parse($pluginDir . '/' . self::CONFIG_YML), $enable ? 'enable' : 'disable');
        return true;
    }

    public function disable(\Eccube\Entity\Plugin $plugin)
    {
        return $this->enable($plugin, false);
    }

    public function update(\Eccube\Entity\Plugin $plugin, $path)
    {
        try {
            $tmp = $this->createTempDir();

            $this->unpackPluginArchive($path, $tmp); //一旦テンポラリに展開
            $this->checkPluginArchiveContent($tmp);

            $config = $this->readYml($tmp . '/' . self::CONFIG_YML);
            $event = $this->readYml($tmp . '/event.yml');

            if ($plugin->getCode() != $config['code']) {
                throw new PluginException('new/old plugin code is different.');
            }
            if ($plugin->getName() != $config['name']) {
                throw new PluginException('new/old plugin name is different.');
            }

            $pluginBaseDir = $this->calcPluginDir($config['code']);
            $this->deleteFile($tmp); // テンポラリのファイルを削除

            $this->unpackPluginArchive($path, $pluginBaseDir); // 問題なければ本当のplugindirへ

            $this->updatePlugin($plugin, $config, $event); // dbにプラグイン登録
            $this->callPluginManagerMethod($config, 'update');

        } catch (PluginException $e) {
            if (file_exists($tmp)) {
                $fs = new Filesystem();
                $fs->remove($tmp);
            }
            throw $e;
        }

        return true;
    }


    public function calcPluginDir($name)
    {
        return $this->app['config']['plugin_realdir'] . '/' . $name;
    }

    public function checkSamePlugin($code)
    {
        $repo = $this->app['eccube.repository.plugin']->findOneBy(array('code' => $code));
        if ($repo) {
            throw new PluginException('plugin already installed.');
        }

    }

    public function checkPluginArchiveContent($dir)
    {
        $meta = $this->readYml($dir . '/config.yml');
        if (!is_array($meta)) {
            throw new PluginException('config.yml not found or syntax error');
        }
        if (!isset($meta['code']) or !$this->checkSymbolName($meta['code'])) {
            throw new PluginException('config.yml code empty or invalid_character(\W)');
        }
        if (!isset($meta['name'])) {
            // nameは直接クラス名やPATHに使われるわけではないため文字のチェックはなしし
            throw new PluginException('config.yml name empty');
        }
        if (isset($meta['event']) and !$this->checkSymbolName($meta['event'])) { // eventだけは必須ではない
            throw new PluginException('config.yml event empty or invalid_character(\W) ');
        }
        if (!isset($meta['version'])) {
            // versionは直接クラス名やPATHに使われるわけではないため文字のチェックはなしし
            throw new PluginException('config.yml version invalid_character(\W) ');
        }
    }

    public function checkSymbolName($string)
    {
        return strlen($string) < 256 && preg_match('/^\w+$/', $string);
        // plugin_nameやplugin_codeに使える文字のチェック
        // a-z A-Z 0-9 _
        // ディレクトリ名などに使われれるので厳しめ
    }

    public function readYml($yml)
    {
        return Yaml::Parse($yml);
    }

    public function createTempDir()
    {

        @mkdir($this->app['config']['plugin_temp_realdir']);
        $d = ($this->app['config']['plugin_temp_realdir'] . '/' . sha1(Str::random(16)));

        if (!mkdir($d, 0777)) {
            throw new PluginException($php_errormsg . $d);
        }
        return $d;

    }

    public function createPluginDir($d)
    {
        $b = mkdir($d);
        if (!$b) {
            throw new PluginException($php_errormsg);
        }
    }

    public function unpackPluginArchive($archive, $dir)
    {
        $extension = pathinfo($archive, PATHINFO_EXTENSION);
        try {
            if ($extension == 'zip') {
                $zip = new \ZipArchive();
                $zip->open($archive);
                $zip->extractTo($dir);
                $zip->close();
            } else {
                $phar = new \PharData($archive);
                $phar->extractTo($dir, null, true);
            }
        } catch (\Exception $e) {
            throw new PluginException('アップロードに失敗しました。圧縮ファイルを確認してください。');
        }
    }

    public function updatePlugin(\Eccube\Entity\Plugin $plugin, $meta, $event_yml)
    {
        $em = $this->app['orm.em'];
        $em->getConnection()->beginTransaction();
        $plugin->setVersion($meta['version'])
            ->setName($meta['name']);

        if (isset($meta['event'])) {
            $plugin->setClassName($meta['event']);
        }

        $rep = $this->app['eccube.repository.plugin_event_handler'];

        if (is_array($event_yml)) {
            foreach ($event_yml as $event => $handlers) {
                foreach ($handlers as $handler) {
                    if (!$this->checkSymbolName($handler[0])) {
                        throw new PluginException('Handler name format error');
                    }
                    // updateで追加されたハンドラかどうか調べる
                    $peh = $rep->findBy(array('del_flg' => Constant::DISABLED,
                        'plugin_id' => $plugin->getId(),
                        'event' => $event,
                        'handler' => $handler[0],
                        'handler_type' => $handler[1]));

                    if (!$peh) { // 新規にevent.ymlに定義されたハンドラなのでinsertする
                        $peh = new \Eccube\Entity\PluginEventHandler();
                        $peh->setPlugin($plugin)
                            ->setEvent($event)
                            ->setdelFlg(Constant::DISABLED)
                            ->setHandler($handler[0])
                            ->setHandlerType($handler[1])
                            ->setPriority($rep->calcNewPriority($event, $handler[1]));
                        $em->persist($peh);
                        $em->flush();

                    }
                }
            }

            # アップデート後のevent.ymlで削除されたハンドラをdtb_plugin_event_handlerから探して削除
            foreach ($rep->findBy(array('del_flg' => Constant::DISABLED, 'plugin_id' => $plugin->getId())) as $peh) {
                if (!isset($event_yml[$peh->getEvent()])) {
                    $em->remove($peh);
                    $em->flush();
                } else {
                    $match = false;
                    foreach ($event_yml[$peh->getEvent()] as $handler) {
                        if ($peh->getHandler() == $handler[0] and $peh->getHandlerType() == $handler[1]) {
                            $match = true;
                        }
                    }
                    if (!$match) {
                        $em->remove($peh);
                        $em->flush();
                    }
                }
            }
        }
        $em->persist($plugin);
        $em->flush();
        $em->getConnection()->commit();
    }

    public function registerPlugin($meta, $event_yml, $source = 0)
    {

        $em = $this->app['orm.em'];
        $em->getConnection()->beginTransaction();
        try {

            $p = new \Eccube\Entity\Plugin();
            $p->setName($meta['name'])
                ->setEnable(Constant::ENABLED)
                ->setClassName(isset($meta['event']) ? $meta['event'] : '')
                ->setVersion($meta['version'])
                ->setDelflg(Constant::DISABLED)
                ->setSource($source)
                ->setCode($meta['code']);

            $em->persist($p);
            $em->flush();

            if (is_array($event_yml)) {
                foreach ($event_yml as $event => $handlers) {
                    foreach ($handlers as $handler) {
                        if (!$this->checkSymbolName($handler[0])) {
                            throw new PluginException('Handler name format error');
                        }
                        $peh = new \Eccube\Entity\PluginEventHandler();
                        $peh->setPlugin($p)
                            ->setEvent($event)
                            ->setdelFlg(Constant::DISABLED)
                            ->setHandler($handler[0])
                            ->setHandlerType($handler[1])
                            ->setPriority($this->app['eccube.repository.plugin_event_handler']->calcNewPriority($event, $handler[1]));
                        $em->persist($peh);
                        $em->flush();
                    }
                }
            }

            $em->persist($p);
            $em->flush();
            $em->getConnection()->commit();
        } catch (\Exception $e) {
            $em->getConnection()->rollback();
            throw new PluginException($e->getMessage());
        }

        return $p;

    }

    public function unregisterPlugin(\Eccube\Entity\Plugin $p)
    {
        $em = $this->app['orm.em'];
        $em->getConnection()->beginTransaction();

        $p->setDelFlg(Constant::ENABLED)->setEnable(Constant::DISABLED);

        foreach ($p->getPluginEventHandlers()->toArray() as $peh) {
            $peh->setDelFlg(Constant::ENABLED);
        }

        $em->persist($p);
        $em->flush();

        $em->getConnection()->commit();
    }

    public function callPluginManagerMethod($meta, $method)
    {
        $class = '\\Plugin' . '\\' . $meta['code'] . '\\' . 'PluginManager';
        if (class_exists($class)) {
            $installer = new $class(); // マネージャクラスに所定のメソッドがある場合だけ実行する
            if (method_exists($installer, $method)) {
                $installer->$method($meta, $this->app);
            }
        }
    }

    public function deleteFile($path)
    {
        $f = new Filesystem();
        $f->remove($path);
    }

}
