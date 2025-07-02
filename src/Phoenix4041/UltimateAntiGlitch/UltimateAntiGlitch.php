<?php

declare(strict_types=1);

namespace Phoenix4041\UltimateAntiGlitch;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\player\Player;
use pocketmine\item\VanillaItems;
use pocketmine\entity\projectile\EnderPearl;
use pocketmine\world\Position;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;

class UltimateAntiGlitch extends PluginBase implements Listener {
    
    private array $pearlCooldowns = [];
    private array $blockBreakData = [];
    private array $playerPositions = [];
    private array $disconnectData = [];
    private Config $config;
    
    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->config = $this->getConfig();
        
        $this->getLogger()->info("UltimateAntiGlitch Plugin by Phoenix4041 - Activado correctamente!");
        
        // Tarea para verificar posiciones de jugadores cada segundo
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(
            function(): void {
                $this->checkPlayerPositions();
            }
        ), 20); // Cada segundo (20 ticks)
    }
    
    public function onDisable(): void {
        $this->getLogger()->info("UltimateAntiGlitch Plugin - Desactivado");
    }
    
    /**
     * Maneja el uso de perlas de ender
     */
    public function onPlayerItemUse(PlayerItemUseEvent $event): void {
        $player = $event->getPlayer();
        $item = $event->getItem();
        
        if ($item->equals(VanillaItems::ENDER_PEARL(), true, false)) {
            $playerName = $player->getName();
            
            // Verificar cooldown
            if (isset($this->pearlCooldowns[$playerName])) {
                $timeLeft = $this->pearlCooldowns[$playerName] - time();
                if ($timeLeft > 0) {
                    $event->cancel();
                    $player->sendMessage($this->config->get("messages")["pearl-cooldown"]);
                    return;
                }
            }
            
            // Verificar si el jugador está en una posición válida
            if ($this->isPlayerInSuspiciousPosition($player)) {
                $event->cancel();
                $player->sendMessage($this->config->get("messages")["pearl-blocked"]);
                
                if ($this->config->get("log-glitches")) {
                    $this->getLogger()->warning("Jugador {$playerName} intentó usar perla en posición sospechosa");
                }
                
                if ($this->config->get("kick-on-glitch")) {
                    $player->kick($this->config->get("messages")["kick-message"]);
                }
                return;
            }
            
            // Establecer cooldown
            $this->pearlCooldowns[$playerName] = time() + $this->config->get("pearl-cooldown");
        }
    }
    
    /**
     * Maneja el lanzamiento de proyectiles (perlas)
     */
    public function onProjectileLaunch(ProjectileLaunchEvent $event): void {
        $entity = $event->getEntity();
        
        if ($entity instanceof EnderPearl) {
            $shooter = $entity->getOwningEntity();
            
            if ($shooter instanceof Player) {
                $this->trackPearlMovement($shooter, $entity);
            }
        }
    }
    
    /**
     * Maneja cuando un jugador rompe un bloque
     */
    public function onBlockBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $playerName = $player->getName();
        
        // Registrar el intento de rotura de bloque
        $this->blockBreakData[$playerName] = [
            "position" => $block->getPosition(),
            "time" => time(),
            "completed" => false
        ];
        
        // Programar verificación después del tiempo de timeout
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function() use ($playerName): void {
                $this->checkBlockBreakGlitch($playerName);
            }
        ), $this->config->get("block-break-timeout") * 20);
    }
    
    /**
     * Maneja cuando un jugador se desconecta
     */
    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        // Si el jugador estaba rompiendo un bloque, marcarlo como sospechoso
        if (isset($this->blockBreakData[$playerName]) && !$this->blockBreakData[$playerName]["completed"]) {
            $this->disconnectData[$playerName] = [
                "disconnect_time" => time(),
                "block_position" => $this->blockBreakData[$playerName]["position"],
                "suspicious" => true
            ];
            
            if ($this->config->get("log-glitches")) {
                $this->getLogger()->warning("Jugador {$playerName} se desconectó mientras rompía un bloque - posible glitch");
            }
        }
        
        // Limpiar datos del jugador
        unset($this->pearlCooldowns[$playerName]);
        unset($this->playerPositions[$playerName]);
    }
    
    /**
     * Maneja cuando un jugador se conecta
     */
    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        // Verificar si el jugador había usado glitch de desconexión
        if (isset($this->disconnectData[$playerName]) && $this->disconnectData[$playerName]["suspicious"]) {
            $timeSinceDisconnect = time() - $this->disconnectData[$playerName]["disconnect_time"];
            
            // Si se reconectó muy rápido, es sospechoso
            if ($timeSinceDisconnect < 60) { // 1 minuto
                if ($this->config->get("log-glitches")) {
                    $this->getLogger()->warning("Jugador {$playerName} se reconectó rápidamente después de desconexión sospechosa");
                }
                
                $player->sendMessage($this->config->get("messages")["glitch-detected"]);
                
                if ($this->config->get("kick-on-glitch")) {
                    $this->getScheduler()->scheduleDelayedTask(new ClosureTask(
                        function() use ($player): void {
                            if ($player->isOnline()) {
                                $player->kick($this->config->get("messages")["kick-message"]);
                            }
                        }
                    ), 20); // Kickear después de 1 segundo
                }
            }
            
            unset($this->disconnectData[$playerName]);
        }
        
        // Inicializar posición del jugador
        $this->playerPositions[$playerName] = $player->getPosition();
    }
    
    /**
     * Verifica si un jugador está en una posición sospechosa para usar perlas
     */
    private function isPlayerInSuspiciousPosition(Player $player): bool {
        $position = $player->getPosition();
        $world = $position->getWorld();
        
        // Verificar si está dentro de bloques sólidos
        $blockAt = $world->getBlock($position);
        $blockAbove = $world->getBlock($position->add(0, 1, 0));
        $blockBelow = $world->getBlock($position->add(0, -1, 0));
        
        // Si está rodeado de bloques sólidos, es sospechoso
        if ($blockAt->isSolid() || ($blockAbove->isSolid() && $blockBelow->isSolid())) {
            return true;
        }
        
        // Verificar movimiento repentino (teletransporte)
        $playerName = $player->getName();
        if (isset($this->playerPositions[$playerName])) {
            $lastPosition = $this->playerPositions[$playerName];
            $distance = $position->distance($lastPosition);
            
            // Si se movió más de la distancia máxima permitida instantáneamente
            if ($distance > $this->config->get("max-pearl-distance")) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Rastrea el movimiento de perlas para detectar glitches
     */
    private function trackPearlMovement(Player $player, EnderPearl $pearl): void {
        $playerName = $player->getName();
        
        // Verificar la trayectoria de la perla cada tick
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(
            function() use ($pearl, $player, $playerName): void {
                if ($pearl->isClosed() || !$pearl->isAlive()) {
                    return;
                }
                
                $pearlPos = $pearl->getPosition();
                $world = $pearlPos->getWorld();
                $block = $world->getBlock($pearlPos);
                
                // Si la perla está atravesando bloques sólidos de manera sospechosa
                if ($block->isSolid()) {
                    $pearl->close();
                    
                    if ($player->isOnline()) {
                        $player->sendMessage($this->config->get("messages")["glitch-detected"]);
                        
                        if ($this->config->get("log-glitches")) {
                            $this->getLogger()->warning("Perla de {$playerName} atravesó bloque sólido - posible glitch");
                        }
                        
                        if ($this->config->get("kick-on-glitch")) {
                            $player->kick($this->config->get("messages")["kick-message"]);
                        }
                    }
                }
            }
        ), 1); // Cada tick
    }
    
    /**
     * Verifica las posiciones de los jugadores para detectar movimientos sospechosos
     */
    private function checkPlayerPositions(): void {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $playerName = $player->getName();
            $currentPosition = $player->getPosition();
            
            if (isset($this->playerPositions[$playerName])) {
                $lastPosition = $this->playerPositions[$playerName];
                $distance = $currentPosition->distance($lastPosition);
                
                // Detectar teletransporte sospechoso (más de 20 bloques en 1 segundo)
                if ($distance > 20) {
                    if ($this->config->get("log-glitches")) {
                        $this->getLogger()->warning("Jugador {$playerName} se movió {$distance} bloques en 1 segundo - posible glitch");
                    }
                    
                    // Verificar si no es teletransporte legítimo (comando, plugin, etc.)
                    if (!$player->hasPermission("ultimateantiglitch.bypass")) {
                        $player->teleport($lastPosition);
                        $player->sendMessage($this->config->get("messages")["glitch-detected"]);
                    }
                }
            }
            
            $this->playerPositions[$playerName] = $currentPosition;
        }
    }
    
    /**
     * Verifica si hubo glitch en la rotura de bloques
     */
    private function checkBlockBreakGlitch(string $playerName): void {
        if (!isset($this->blockBreakData[$playerName])) {
            return;
        }
        
        $data = $this->blockBreakData[$playerName];
        
        // Si el bloque no se completó de romper y el jugador sigue online
        if (!$data["completed"]) {
            $player = $this->getServer()->getPlayerExact($playerName);
            
            if ($player !== null && $player->isOnline()) {
                // El jugador está online pero no completó la rotura - posible lag o glitch
                if ($this->config->get("log-glitches")) {
                    $this->getLogger()->info("Jugador {$playerName} no completó rotura de bloque - posible lag de red");
                }
            }
        }
        
        unset($this->blockBreakData[$playerName]);
    }
}