<?php
/*!
 * @file TelegramWebhook.php
 * @author Sensu Development Team
 * @date 2018/02/24
 * @brief Telegram用Sensuクライアント
 */

require_once __DIR__.'/Config.php';
require_once __DIR__.'/SensuClient.php';
require __DIR__.'/vendor/autoload.php';

class TelegramWebhook
{
    /*!
     * @brief SensuプラットフォームAPIクライアント
     */
    private $sensu;

    /*!
     * @brief Telegram APIクライアント
     */
    private $telegram;

    /*!
     * @brief コンストラクタ
     */
    public function __construct()
    {
        $this->sensu = new \SensuDevelopmentTeam\SensuClient(Config::SENSU_PLATFORM_API_KEY);
        $loop = \React\EventLoop\Factory::create();
        $handler = new \unreal4u\TelegramAPI\HttpClientRequestHandler($loop);
        $client = new \unreal4u\TelegramAPI\TgLog(Config::TELEGRAM_API_TOKEN, $handler);
        $this->telegram = array(
            'client' => $client,
            'loop' => $loop
        );
    }

    /*!
     * @brief フック
     */
    public function hook()
    {
        // 受信したJSONをパース
        $request = file_get_contents('php://input');
        $request = json_decode($request);
        if(!$request)
        {
            return;
        }
        // メッセージでなければ中止
        if (!isset($request->message))
        {
            return;
        }

        // 接頭辞
        $prefix = '/';
        // 本文の先頭が接頭辞でなければ中止
        if (strncmp($request->message->text, $prefix, strlen($prefix)))
        {
            return;
        }

        // 送信者の情報を取得
        $get_chat_member = new \unreal4u\TelegramAPI\Telegram\Methods\GetChatMember();
        $get_chat_member->chat_id = $request->message->chat->id;
        $get_chat_member->user_id = $request->message->from->id;
        $sender = \Clue\React\Block\await($this->telegram['client']->performApiRequest($get_chat_member), $this->telegram['loop'])->user;

        // 接頭辞を削除
        $command = substr($request->message->text, strlen($prefix), strlen($request->message->text) - strlen($prefix));
        // 命令を分解
        $command = $this::getCommandFromText($command);

        // 投げ銭コマンド
        if (isset($command[0]) && strcasecmp($command[0], 'tip') == 0)
        {
            if (isset($command[3]))
            {
                try
                {
                    $command[3] = strval($this::getIdFromUserName($command[3]));
                }
                catch (Exception $e)
                {
                    $command[3] = '';
                }
            }
        }

        // 命令を送信
        $result = $this->sensu->command(strval($request->message->from->id), $command);

        $sender_mention = '[';
        if (!empty($sender->username))
        {
            $sender_mention .= '@'.$sender->username;
        }
        else
        {
            $sender_mention .= $sender->first_name;
        }
        $sender_mention .= '](tg://user?id=';
        $sender_mention .= $request->message->from->id;
        $sender_mention .= ')';

        // プッシュメッセージ
        if (isset($result->push_message))
        {
            // 投げ銭コマンド
            if (isset($command[0]) && strcasecmp($command[0], 'tip') == 0)
            {
                $send_message_push = new \unreal4u\TelegramAPI\Telegram\Methods\SendMessage();
                $send_message_push->chat_id = intval($command[3]);
                $send_message_push->text = sprintf($result->push_message, $sender_mention);
                $send_message_push->parse_mode = 'Markdown';
                \Clue\React\Block\await($this->telegram['client']->performApiRequest($send_message_push), $this->telegram['loop']);
            }
        }

        // 返信
        $send_message = new \unreal4u\TelegramAPI\Telegram\Methods\SendMessage();
        $send_message->chat_id = $request->message->chat->id;
        // 表示用メッセージが設定されていなければ内部エラー
        if (!isset($result->message))
        {
            $send_message->text = $sender_mention."\n内部エラーが発生しました。\nAn internal error occurred.";
        }
        else
        {
            $send_message->text .= $sender_mention."\n".$result->message;
        }
        $send_message->parse_mode = 'Markdown';
        \Clue\React\Block\await($this->telegram['client']->performApiRequest($send_message), $this->telegram['loop']);
    }

    /*!
     * @brief 本文より命令を取得
     * @param $test 本文
     * @return 命令
     */
    private static function getCommandFromText($text)
    {
        $command = htmlspecialchars_decode($text, ENT_NOQUOTES);
        $result = preg_split('/[ \n](?=(?:[^\\"]*\\"[^\\"]*\\")*(?![^\\"]*\\"))/', $command, -1, PREG_SPLIT_NO_EMPTY);
        $result = str_replace('"', '', $result);
        return $result;
    }
    
    /*
     * @brief ユーザー名からIDを取得
     * @param $user_nameユーザー名
     * @return ID
     */
    public static function getIdFromUserName($user_name)
    {
        $ch = curl_init('https://api.pwrtelegram.xyz/bot'.Config::TELEGRAM_API_TOKEN.'/getChat?chat_id='.$user_name);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = json_decode(curl_exec($ch));
        curl_close($ch);
        if (!$response->ok)
        {
            throw new Exception('API error.');
        }
        return $response->result->id;
    }
}

try
{
    $webhook = new TelegramWebhook();
    $webhook->hook();
}
catch (Exception $e)
{
    // Webhook再リクエスト防止の為何もしない
}
