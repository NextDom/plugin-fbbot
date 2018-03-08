<?php

/* This file is part of the Jeedom Facebook Messenger plugion.
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


header('Content-type: application/json');
require_once dirname(__FILE__) . "/../../../../core/php/core.inc.php";

$verify_token     = jeedom::getApiKey('fbbot');
$hub_verify_token = null;

if (!isset($_REQUEST['hub_challenge']) && !isset($_SERVER['HTTP_X_HUB_SIGNATURE'])) {
    echo "payload error";
    die();
}

if (isset($_REQUEST['hub_challenge'])) {
    $challenge        = $_REQUEST['hub_challenge'];
    $hub_verify_token = $_REQUEST['hub_verify_token'];

    if ($hub_verify_token === $verify_token) {
        echo $challenge;
        die();
    } else {
        echo "Token de vérification KO";
        die();
    }
}

if (isset($_SERVER['HTTP_X_HUB_SIGNATURE'])) {
    $content = file_get_contents('php://input');
    $json    = json_decode($content, true);

    $eqLogic = fbbot::byLogicalId($json['entry'][0]['id'], 'fbbot');
    if (!is_object($eqLogic)) {
        echo json_encode(array('text' => __('Page inconnue : ', __FILE__) . $json['entry'][0]['id']));
        die();
    }

    if ("sha1=" . hash_hmac('sha1', $content, $eqLogic->getConfiguration('app_secret')) != $_SERVER['HTTP_X_HUB_SIGNATURE']) {
        die();
    }
}

$access_token = $eqLogic->getConfiguration('access_token');

foreach ($json['entry'] as $entry) {
    foreach ($entry['messaging'] as $messaging) {
        $sender            = $messaging['sender']['id'];
        $message           = $messaging['message']['text'];
        $page_id           = $messaging['recipient']['id'];
        $quickReplyPayLoad = $messaging['message']['quick_reply']['payload'];

        if (isset($message) && isset($sender)) {
            $cmd_text = $eqLogic->getCmd('info', 'text');
            $cmd_text->event($message);

            $cmd_sender = $eqLogic->getCmd('info', 'sender');
            $cmd_sender->event($sender);
        } else {
            continue;
        }

        // gestion des quick Replies
        foreach ($eqLogic->getCmd('action') as $cmd) {
            if (isset($quickReplyPayLoad) && $cmd->askResponse($message)) {
                echo json_encode(array('text' => ''));
                continue 2;
            }
        }

        // vérification de l'utlisateur demandeur
        $cmd_user = $eqLogic->getCmd('action', $sender);
        if (!is_object($cmd_user)) {
            if ($eqLogic->getConfiguration('isAccepting') == 1) {
                $cmd_user = (new fbbotCmd())
                        ->setLogicalId($sender)
                        ->setIsVisible(1)
                        ->setName("New user")
                        ->setConfiguration('interact', 0)
                        ->setConfiguration('fb_user_id', $sender)
                        ->setConfiguration('jeedom_username', 'admin')
                        ->setType('action')
                        ->setSubType('message')
                        ->setEqLogic_id($eqLogic->getId());
                $cmd_user->save();
            } else {
                continue;
            }
        }

        $user_profile = $cmd_user->getConfiguration('jeedom_username') != "" ? $cmd_user->getConfiguration('jeedom_username') : null;
        $parameters   = array();

        $user = user::byLogin($user_profile);
        if (is_object($user)) {
            $parameters['profile'] = $user_profile;
        }

        if ($cmd_user->getConfiguration('interact') == 1) {
            $parameters['plugin'] = 'fbbot';
            log::add('fbbot', 'debug', 'Interaction ' . print_r($reply, true));
            $result_jeedom        = interactQuery::tryToReply(trim($message), $parameters);
            if (is_array($result_jeedom)) {
                $message_to_reply .= implode($result_jeedom);
            } else {
                $message_to_reply .= $result_jeedom;
            }
        } else {
            $message_to_reply = 'Utilisateur non habilité';
        }

        //API Url
        $url             = 'https://graph.facebook.com/v2.6/me/messages?access_token=' . $access_token;
        //Initiate cURL.
        $ch              = curl_init($url);
        //The JSON data.
        $jsonData        = '{
           "messaging_type": "RESPONSE",
		   "recipient":{
		        "id":"' . $sender . '"
		    },
		    "message":{
		        "text":"' . $message_to_reply . '"
		    }
		}';
        //Encode the array into JSON.
        $jsonDataEncoded = $jsonData;
        //Tell cURL that we want to send a POST request.
        curl_setopt($ch, CURLOPT_POST, 1);
        //Attach our encoded JSON string to the POST fields.
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonDataEncoded);
        //Set the content type to application/json
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        //curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
        //Execute the request
        if (!empty($messaging['message'])) {
            $result = curl_exec($ch);
        }
    }
}
