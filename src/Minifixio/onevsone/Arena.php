<?php

namespace Minifixio\onevsone;

//Pocketmine imports
use pocketmine\level\Location;
use pocketmine\level\Position;
use pocketmine\scheduler\TaskHandler;
use pocketmine\Server;
use pocketmine\Player;
use pocketmine\item\Item;
use pocketmine\utils\TextFormat;
use pocketmine\math\Vector3;
use pocketmine\level\particle\DestroyBlockParticle;
use pocketmine\block\Block;

//Plugin imports
use Minifixio\onevsone\Tasks\CountDownToDuelTask;
use Minifixio\onevsone\Tasks\GameTimeTask;

//Date imports.
use \DateTime;

class Arena{

    public $active = false;

    public $startTime;

    public $players = array();

    private $plugin;

    /** @var Location */
    public $spawn1;

    /** @var Location */
    public $spawn2;

    /** @var ArenaManager */
    private $manager;

    // Round duration (3min)
    // Default match duration
    private $duration = 180;

    /** @var TaskHandler */
    private $gameTimeTaskHandler;
    /** @var TaskHandler */
    private $countdownTaskHandler;

    /** @var array */
    private $inventories = [];

    /**
     * Build a new Arena
     *
     * @param Location $spawn1
     * @param Location $spawn2
     * @param ArenaManager $manager
     */
    public function __construct(Location $spawn1, Location $spawn2, ArenaManager $manager){
        $this->spawn1 = $spawn1;
        $this->spawn2 = $spawn2;
        $this->manager = $manager;
        $this->duration = OneVsOne::getInstance()->getConfig()->get("time-limit") * 60;
        $this->active = false;

        $this->plugin = OneVsOne::getInstance();
    }

    /**
     * Start a match.
     * @param Player[] $players
     */
    public function startRound(array $players){
        foreach($players as $p) $this->inventories[$p->getName()] = $p->getInventory()->getContents();

        // Set active to prevent new players
        $this->active = true;

        // Set players
        $this->players = $players;
        $player1 = $players[0];
        $player2 = $players[1];

        $player1->sendMessage(OneVsOne::getMessage("duel_against") . $player2->getName()); //To-Do customise to make it better.
        $player2->sendMessage(OneVsOne::getMessage("duel_against") . $player1->getName()); //To-Do customise to make it better.

        // Create a new countdowntask
        $task = new CountDownToDuelTask(OneVsOne::getInstance(), $this);
        $this->countdownTaskHandler = $this->plugin->getScheduler()->scheduleRepeatingTask($task, 20);

        //imjay addition
        $task = new GameTimeTask(OneVsOne::getInstance(), $this);
        $this->gameTimeTaskHandler = $this->plugin->getScheduler()->scheduleDelayedRepeatingTask($task, CountDownToDuelTask::COUNTDOWN_DURATION * 20, 20);
    }

    /**
     * Really starts the duel after countdown
     */
    public function startDuel(){
        $this->plugin->getScheduler()->cancelTask($this->countdownTaskHandler->getTaskId());

        /** @var Player $player1 */
        $player1 = $this->players[0];
        /** @var Player $player2 */
        $player2 = $this->players[1];
        var_dump($this->spawn1);
        var_dump($this->spawn2);
        $player1->teleport($this->spawn1);
        $player2->teleport($this->spawn2);
        $this->sparyParticle($player1);
        $this->sparyParticle($player2);
        $player1->setGamemode(0);
        $player2->setGamemode(0);

        // Give kit
        if(OneVsOne::getInstance()->getConfig()->get("force-kit") === true){
            foreach($this->players as $player){
                $this->giveKit($player);
            }
        }
        // Fix start time
        $this->startTime = new DateTime('now');

        $player1->sendTip(OneVsOne::getMessage("duel_tip"));
        $player1->sendMessage(str_replace("{roundtime}", OneVsOne::getInstance()->getConfig()->get("time-limit"), OneVsOne::getMessage("duel_start")));

        $player2->sendTip(OneVsOne::getMessage("duel_tip"));
        $player2->sendMessage(str_replace("{roundtime}", OneVsOne::getInstance()->getConfig()->get("time-limit"), OneVsOne::getMessage("duel_start")));
    }

    /**
     * Abort duel during countdown if one of the players has quit
     */
    public function abortDuel(){
        $this->plugin->getScheduler()->cancelTask($this->countdownTaskHandler->getTaskId());
    }

    private function giveKit(Player $player){
        // Clear inventory
        $player->getInventory()->clearAll();
	     // Set his life to 20
        $player->setHealth(20);
        $player->removeAllEffects();
	     $items = [];
	    foreach($player as $item) {
            $item = self::parseItems($item);
            if($item instanceof Item) {
                $items[] = $item;
            }
        }
        return $items;
    }
     /* Parse an Item
     *
     * @param string $string
     * @return null|Item
     */
    public static function parseItem($string): ?Item {
        $array = explode(",", $string);
        foreach($array as $key => $value) {
            $array[$key] = (int) $value;
        }
        if(isset($array[1])) {
            $item = Item::get($array[0], $array[1], $array[2]);
            if(isset($array[4])) {
                $item->addEnchantment(new EnchantmentInstance(Enchantment::getEnchantment($array[3]), $array[4]));
            }
            return $item;
        }
        else {
            return null;
        }
    }
    
    /**
     * Parse items
     *
     * @param array $array
     * @return []Item
     */
    public static function parseItems($array): array {
        $items = [];
        foreach($array as $item) {
            $item = self::parseItem($item);
            if($item instanceof Item) {
                $items[] = $item;
            }
        }
        return $items;
    }


    /**
     * When a player was killed
     * @param Player $loser
     */
    public function onPlayerDeath(Player $loser){
        // Finish the duel and teleport the winner at spawn
        $loser == $this->players[0] ? $winner = $this->players[1] : $winner = $this->players[0];

        $loser->sendMessage(str_replace(["{winner}"], [$winner->getName()], OneVsOne::getMessage("duel_loser")));
        $loser->removeAllEffects();

        $winner->sendMessage(str_replace(["{loser}"], [$loser->getName()], OneVsOne::getMessage("duel_winner")));
        $winner->removeAllEffects();

        // Teleport the winner to spawn
        $winner->teleport($winner->getSpawn());

        // Heal winner
        $winner->setHealth(20);
        $winner->getInventory()->clearAll();
        $winner->getArmorInventory()->clearAll();
        $this->plugin->getServer()->broadcastMessage(str_replace(["{winner}", "{loser}"], [$winner->getName(), $loser->getName()], OneVsOne::getMessage("duel_broadcast")));

        // Reset arena
        $this->reset();
    }

    /**
     * Reset the Arena
     */
    private function reset(){
        // Put active arena after the duel
        $this->active = false;
        if(OneVsOne::getInstance()->getConfig()->get("force-kit") === true){
            foreach($this->players as $player){
                $player->getInventory()->clearAll();
                $player->getArmorInventory()->clearAll();
            }
            $this->players = array();
            $this->startTime = null;
            if($this->gameTimeTaskHandler != null){
                $this->plugin->getScheduler()->cancelTask($this->gameTimeTaskHandler->getTaskId());
                $this->manager->notifyEndOfRound($this);
            }
        }

        foreach($this->inventories as $owner => $inv){
            foreach($inv as $item) OneVsOne::getInstance()->getServer()->getPlayer($owner)->getInventory()->addItem($item);
        }
    }

    /**
     * When a player quits the game
     * @param Player $loser
     */
    public function onPlayerQuit(Player $loser){
        // Finish the duel when a player quit
        // With onPlayerDeath() function
        $this->onPlayerDeath($loser);
    }

    /**
     * When maximum round time is reached
     */
    public function onRoundEnd(){
        foreach($this->players as $player){
            $player->teleport($player->getSpawn());
            $player->sendMessage(OneVsOne::getMessage("duel_timeover"));
            $player->removeAllEffects();
            $player->getArmorInventory()->clearAll();
            $player->getInventory()->clearAll();
        }

        // Reset arena
        $this->reset();
    }

    public function isPlayerInArena(Player $player){
        return in_array($player, $this->players);
    }

    public function sparyParticle(Player $player){
        $particle = new DestroyBlockParticle(new Vector3($player->getX(), $player->getY(), $player->getZ()), Block::get(8));
        $player->getLevel()->addParticle($particle);
    }
}
