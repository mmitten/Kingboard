<?php
namespace Kingboard\Views;
class BattleEditor extends \Kingboard\Views\Base
{
    public function __construct()
    {
        // require user to be logged in for this view
        parent::__construct(true);
    }

    public function index(array $params)
    {
        $user = \Kingboard\Lib\Auth\Auth::getUser();
        $context = array();

        if(isset($user['keys']))
            $activeKeys = $user['keys'];
        else $activeKeys = array();

        $charkeylist = array();
        foreach($activeKeys as $id => $key)
        {
            try {
                $pheal = new \Pheal($key['apiuserid'], $key['apikey']);
                $chars = $pheal->accountScope->Characters()->characters->toArray();
                $charlist = array();
                foreach($chars as $char)
                {
                    $charkeylist[$key['apiuserid'] . "|" . $char['characterID']] = $char['name'] . " (".$key['type'] .")";
                    $charlist[] = $char['name'];
                }
                $activeKeys[$id]["chars"] = join(', ', $charlist);
            } catch (\PhealAPIException $e) {
                //print_r($e);
            }
        }
        $context = array_merge($context, array(
            'active_characters' => $charkeylist
        ));
        $this->render('battle/battleeditor.html', $context);

    }

    public function create(array $params)
    {
        if(!\Kingboard\Lib\Forms\BattleCreateForm::validate($_POST))
        {
            // @todo handle invalid
            die();
        }
        $user = \Kingboard\Lib\Auth\Auth::getUser();
        list($key, $character) = explode('|', $_POST['character']);
        $key = $user["keys"][$key];
        $pheal = new \Pheal($key['apiuserid'], $key['apikey'], 'char');
        $contacts = $pheal->ContactList(array('characterID' => $character));
        $positives = array();
        foreach($contacts->corporateContactList as $contact)
        {
            // accumulate postive standings
            if($contact->standing > 0)
                $positives[$contact->contactID]= $contact->contactName;
        }
        // alliance standings override corp standings
        foreach($contacts->allianceContactList as $contact)
        {
            if($contact->standing > 0)
            {
                $positives[$contact->contactID]= $contact->contactName;
            } else {
                // negative standings, we only need those if corp has positive, but alliance negative
                if(isset($positives[$contact->contactID]))
                    unset($positives[$contact->contactID]);
            }
        }

        $battleSetting = new \Kingboard\Model\BattleSettings();
        $battleSetting->startdate = new \MongoDate(strtotime($_POST['startdate']));
        $battleSetting->user = $user->_id;
        $battleSetting->enddate = new \MongoDate(strtotime($_POST['enddate']));
        $battleSetting->system = $_POST['system'];
        $battleSetting->key = $key;
        $battleSetting->character = $character;
        $battleSetting->positives = $positives;
        $battleSetting->runs = 0;
        $battleSetting->nextRun = new \MongoDate(time());
        $battleSetting->save();

        // we are done here, lets redirect to the battle!
        $this->redirect("/battle/" . $battleSetting->_id);
    }
}