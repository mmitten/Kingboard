<?php
namespace Kingboard;
class KingboardCronTask extends \King23\Tasks\King23Task
{
    /**
     * documentation for the single tasks
     * @var array
     */
    protected $tasks = array(
        "info" => "General Informative Task",
        "update_stats" => "task to update stats which will be map/reduced from the database",
        "key_activation" => "task to check for new key activates, and activate if so",
        "api_import" => "import",
        "item_values" => "updates item values for all items on the market",
        "idfeed_import" => "stuff",
    );

    /**
     * Name of the module
     */
    protected $name = "KingboardCron";

    /**
     * @param array $options
     * @return void
     */
    public function update_stats(array $options)
    {
        $this->cli->header('updating stats');

        $this->cli->message('updating Kills by Shiptype');
        // stats table of how often all ships have been killed
        \Kingboard\Model\MapReduce\KillsByShip::mapReduce();
        $this->cli->positive('update of KillsByShip stats completed');

        $this->cli->message('updating pilot loss stats');
        \Kingboard\Model\MapReduce\LossesByShipByPilot::mapReduce();
        $this->cli->positive('update of pilot loss stats completed');

        $this->cli->message('updating pilot kill stats');
        \Kingboard\Model\MapReduce\KillsByShipByPilot::mapReduce();
        $this->cli->positive('update of pilot kill stats completed');

        $this->cli->message('updating corporation loss stats');
        \Kingboard\Model\MapReduce\LossesByShipByCorporation::mapReduce();
        $this->cli->positive('update of corporation loss stats completed');

        $this->cli->message('updating corporation kill stats');
        \Kingboard\Model\MapReduce\KillsByShipByCorporation::mapReduce();
        $this->cli->positive('update of corporation kill stats completed');

        $this->cli->message('updating alliance loss stats');
        \Kingboard\Model\MapReduce\LossesByShipByAlliance::mapReduce();
        $this->cli->positive('update of alliance loss stats completed');

        $this->cli->message('updating alliance kill stats');
        \Kingboard\Model\MapReduce\KillsByShipByAlliance::mapReduce();
        $this->cli->positive('update of alliance kill stats completed');

        $this->cli->message('updating faction loss stats');
        \Kingboard\Model\MapReduce\LossesByShipByFaction::mapReduce();
        $this->cli->positive('update of faction loss stats completed');

        $this->cli->message('updating faction kill stats');
        \Kingboard\Model\MapReduce\KillsByShipByFaction::mapReduce();
        $this->cli->positive('update of faction kill stats completed');

        $this->cli->message('updating name lists for search');
        \Kingboard\Model\MapReduce\NameSearch::mapReduce();
        $this->cli->positive("name list updated");
    }

    public function key_activation(array $options)
    {
        $this->cli->header('updating key activates');
        $reg = \King23\Core\Registry::getInstance();

        $pheal = new \Pheal($reg->apimailreceiverApiUserID, $reg->apimailreceiverApiKey, 'char');
        $messages = $pheal->MailMessages(array('characterID' => $reg->apimailreceiverCharacterID))->messages;
        foreach($messages as $message)
        {
            if($message->toCharacterIDs != $reg->apimailreceiverCharacterID)
                continue;

            $token = trim($message->title);

            if(strlen($token) != \Kingboard\Model\ApiActivationToken::TOKEN_LENGTH)
                continue;

            if(!$token = \Kingboard\Model\ApiActivationToken::findOneByToken($token))
                continue;

            $user = \Kingboard\Model\User::getById($token['userid']);
            $keys = $user['keys'];
            $apiuserid = $token['apiuserid'];
            $phealactivate = new \Pheal($keys[$apiuserid]['apiuserid'], $keys[$apiuserid]['apikey']);
            $characters = $phealactivate->Characters()->characters;
            foreach($characters as $character)
            {
                if($character->characterID == $message->senderID)
                {
                    $keys[$apiuserid]['active'] = true;
                    $user['keys'] = $keys;
                    $user->save();
                    $token->delete();

                    //$body = \King23\Core\Registry::getInstance()->sith->cachedGet('mails/activate_apikey.html')->render(array('username' => $user['username'], 'apiuserid' => $apiuserid ), \King23\Core\Registry::getInstance()->sith);
                    //mail($user['username'], "Kingboard API Key Activation", $body);
                    break;
                }
            }
        }
    }

    public function idfeed_import(array $options)
    {
        if(count($options) < 1)
        {
            $this->cli->error('1 parameter required: handle');
            return;
        }
        $this->cli->message('starting import of handle: ' . $options[0]);

        $idfeed = \Kingboard\Model\IdFeed::findByHandle($options[0]);
        if(is_null($idfeed))
        {
            $this->cli->error('no such handle');
            return;
        }

        $lastid =  $idfeed->lastId;
        if(is_null($lastid))
            $lastid = 0;

        if(!is_null($idfeed->type))
            $kidf = new \Kingboard\Lib\Fetcher\IdFeed($idfeed->url, $idfeed->type);
        else
            $kidf = new \Kingboard\Lib\Fetcher\IdFeed($idfeed->url);

        try {
            $kills = $kidf->fetch($lastid)->kills;
            $kakp = new \Kingboard\Lib\Parser\EveAPI();
            $info = $kakp->parseKills($kills);
            $total = $info['oldkills'] + $info['newkills'];
            $this->cli->message("fetched $total kills, " . $info['oldkills'] . " allready known, " . $info['newkills'] . " new");
            $this->cli->message("Last id was: " . $info['lastID'] . " internal: " . $info['lastIntID']);

            if(!is_null($idfeed->type) &&  $idfeed->type == "intid")
                $idfeed->lastId = $info['lastIntID'];
            else
                $idfeed->lastId = $info['lastID'];

            $idfeed->save();
        } catch (\Exception $e )
        {
            $this->cli->error("idfetch exception occured: " . $e->getMessage());
        }
    }

    public function api_import(array $options)
    {
        $this->cli->message("api import running");

        foreach(\Kingboard\Model\User::findWithApiKeys() as $user)
        {
            /** @var \Kingboard\Model\User $user */
            foreach($user['keys'] as $keyid => $key)
            {
                // skip inactive keys
                if(!$key['active'])
                    continue;

                try {
                    $stats = \Kingboard\Lib\Fetcher\EveApi::fetch($key);
                    $this->cli->message("processed key $keyid for user " . $user->name . " : ". $stats['old'] . ' known / ' . $stats['new'] ." new");
                } catch(\PhealException $e) {
                    // we caught a pheal exception, we should implement proper handling of errors here
                    $this->cli->error("key $keyid caused pheal exception: " . $e->getMessage());
                }

            }
        }
    }

    public function item_values(array $options)
    {
        $this->cli->message("item value updating running");
        $result = \Kingboard\Model\EveItem::getMarketIDs();
        $total = $result->count();
        foreach($result as $item)
        {
            $isk = \Kingboard\Lib\EveCentral\Api::getValue($item->typeID);
            if($isk > 0)
            {

                $instance = \Kingboard\Model\EveItem::getByItemId($item->typeID);
                $instance->iskValue = $isk;
                $instance->save();

                $this->cli->message("Successfully updated ".$item->typeID." to ".$isk);
            }
            else
                $this->cli->message("Did not update ".$item->typeID.", it had a value of 0");
        }
    }
}