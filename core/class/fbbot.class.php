<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class fbbot extends eqLogic
{
    /*     * ***********************Methode static*************************** */

    public static function health()
    {
        $https    = strpos(network::getNetworkAccess('external'), 'https') !== false;
        $return[] = array(
            'test'   => __('HTTPS', __FILE__),
            'result' => ($https) ? __('OK', __FILE__) : __('NOK', __FILE__),
            'advice' => ($https) ? '' : __('Votre Jeedom ne permet pas le fonctionnement de Fbbot sans HTTPS (Obligation imposée par Facebook)', __FILE__),
            'state'  => $https,
        );
        return $return;
    }

    public function postSave()
    {
        $sms = $this->getCmd(null, 'text');
        if (!is_object($sms)) {
            $sms = new fbbotCmd();
            $sms->setLogicalId('text');
            $sms->setIsVisible(0);
            $sms->setName(__('Message', __FILE__));
        }
        $sms->setType('info');
        $sms->setSubType('string');
        $sms->setEqLogic_id($this->getId());
        $sms->save();

        $sender = $this->getCmd(null, 'sender');
        if (!is_object($sender)) {
            $sender = new fbbotCmd();
            $sender->setLogicalId('sender');
            $sender->setIsVisible(0);
            $sender->setName(__('Expediteur', __FILE__));
        }
        $sender->setType('info');
        $sender->setSubType('string');
        $sender->setEqLogic_id($this->getId());
        $sender->save();


        $alluser = $this->getCmd(null, 'alluser');
        if (!is_object($alluser)) {
            $alluser = new fbbotCmd();
            $alluser->setLogicalId('alluser');
            $alluser->setIsVisible(1);
            $alluser->setName(__('Tous', __FILE__));
            $alluser->setType('action');
            $alluser->setSubType('message');
            $alluser->setEqLogic_id($this->getId());
            $alluser->save();
        }
    }

}

class fbbotCmd extends cmd
{

    public function preSave()
    {
        if ($this->getSubtype() == 'message') {
            $this->setDisplay('title_disable', 1);
        }
    }

    public function execute($_options = array())
    {
        $eqLogic             = $this->getEqLogic();
        $recipients          = array();
        $currentCmdFbUserId  = $this->getConfiguration('fb_user_id');
        $currentCmdLogicalId = $this->getLogicalId();

        if ($currentCmdLogicalId == "alluser") {
            foreach ($eqLogic->getCmd('action') as $cmd) {
                if ($cmd->getConfiguration('notifications') == 1 && $cmd->getConfiguration('fb_user_id') != "") {
                    $recipients[] = $cmd->getConfiguration('fb_user_id');
                }
            }
        } else {
            if (!isset($currentCmdFbUserId)) {
                echo "erreur -> destinataire inconnu";
                return;
            }
            $recipients[] = $currentCmdFbUserId;
        }

        $access_token = $eqLogic->getConfiguration('access_token');

        $replyMarkup         = null;
        $quick_Replies_array = array();

        // Gestion des réponses rapides
        if (isset($_options['answer'])) {
            $replyMarkup = $_options['answer'];
            if (count($replyMarkup) > 0) {
                for ($i = 0; $i < count($replyMarkup); $i++) {
                    $quick_Replies_array[] = ["content_type" => "text", "title" => $replyMarkup[$i], "payload" => "REPLY_" . $i];
                }
            }
        }

        //API Url
        $url = 'https://graph.facebook.com/v2.6/me/messages?access_token=' . $access_token;
        //Initiate cURL.
        $ch  = curl_init($url);

        foreach ($recipients as $recipient) {

            $filesToUpload = $_options['files'];
            //$filesToUpload = ['/var/www/html/robots.txt','/var/www/html/plugins/fbbot/doc/images/fbbot_icon.png'];
            // gestion des pièces jointes
            foreach ($filesToUpload as $file) {

                $attachment = null;

                $mime     = mime_content_type($file);
                $fileType = "file";
                if (in_array($mime, ['image/png', 'image/gif', 'image/jpg'])) {
                    $fileType = "image";
                } elseif (preg_match("/audio/i", $mime)) {
                    $fileType = "audio";
                } elseif (preg_match("/video/i", $mime)) {
                    $fileType = "video";
                }

                $attachment = ["attachment" => [
                        "type"    => $fileType,
                        "payload" => []
                ]];

                $filedata = new CurlFile(realpath($file), $mime);

                //Encode the array into JSON.
                $postArgs   = ["recipient" => json_encode(["id" => $recipient]), "message" => json_encode($attachment), "filedata" => $filedata];
                //Tell cURL that we want to send a POST request.
                curl_setopt($ch, CURLOPT_POST, 1);
                //Attach our encoded JSON string to the POST fields.
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postArgs);
                //Set the content type to application/json
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:multipart/form-data'));
                $result_req = curl_exec($ch);
            }


            $data = [
                "recipient" => ["id" => $recipient],
                "message"   => ["text" => $_options['message']]
            ];

            if (isset($_options['answer']))
                $data['message']['quick_replies'] = $quick_Replies_array;

            //Encode the array into JSON.
            $jsonDataEncoded = json_encode($data);
            //Tell cURL that we want to send a POST request.
            curl_setopt($ch, CURLOPT_POST, 1);
            //Attach our encoded JSON string to the POST fields.
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonDataEncoded);
            //Set the content type to application/json
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            $result_req      = curl_exec($ch);
        }
        curl_close($ch);
        return;
    }

}
