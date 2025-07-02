<?php

declare(strict_types=1);

namespace Phoenix4041\UltimateAntiGlitch;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\player\Player;
use pocketmine\item\VanillaItems;
use pocketmine\entity\projectile\EnderPearl;
use pocketmine\world\Position;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;

class UltimateAntiGlitch extends PluginBase implements Listener {
    
    private array $pearlCooldowns = [];
    private array $blockBreakData = [];
    private array $playerPositions = [];
    private array $disconnectData = [];
    private array $pearlTracking = [];
    private Config $config;
    
    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->config = $this->getConfig();
        
        $this->getLogger()->info("UltimateAntiGlitch Plugin by Phoenix4041 - Activado correctamente!");
        
        // Tarea para limpiar datos antiguos cada 5 minutos
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(
            function(): void {
                $this->cleanupOldData();
            }
        ), 6000); // Cada 5 minutos (6000 ticks)
    }
    
    public function onDisable(): void {
        $this->getLogger()->info("UltimateAntiGlitch Plugin - Desactivado");
    }
    
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() === "ultimateantiglitch") {
            if (!$sender->hasPermission("ultimateantiglitch.admin")) {
                $sender->sendMessage("§cNo tienes permisos para usar este comando.");
                return true;
            }
            
            if (empty($args)) {
                $sender->sendMessage("§eUso: /ultimateantiglitch <reload|info|check>");
                return true;
            }
            
            switch (strtolower($args[0])) {
                case "reload":
                    $this->reloadConfig();
                    $this->config = $this->getConfig();
                    $sender->sendMessage("§aConfiguración recargada correctamente!");
                    break;
                    
                case "info":
                    $sender->sendMessage("§e=== UltimateAntiGlitch Info ===");
                    $sender->sendMessage("§7Versión: §f1.0.0");
                    $sender->sendMessage("§7Autor: §fPhoenix4041");
                    $sender->sendMessage("§7Jugadores monitoreados: §f" . count($this->playerPositions));
                    $sender->sendMessage("§7Cooldowns activos: §f" . count($this->pearlCooldowns));
                    break;
                    
                case "check":
                    if ($sender instanceof Player) {
                        $this->performPlayerCheck($sender);
                    } else {
                        $sender->sendMessage("§cEste comando solo puede ser usado por jugadores.");
                    }
                    break;
                    
                default:
                    $sender->sendMessage("§eUso: /ultimateantiglitch <reload|info|check>");
                    break;
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Maneja el uso de perlas de ender
     */
    public function onPlayerItemUse(PlayerItemUseEvent $event): void {
        $player = $event->getPlayer();
        $item = $event->getItem();
        
        if ($item->equals(VanillaItems::ENDER_PEARL(), true, false)) {
            $playerName = $player->getName();
            
            // Bypass para administradores
            if ($player->hasPermission("ultimateantiglitch.bypass")) {
                return;
            }
            
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
                    $this->kickPlayer($player, $this->config->get("messages")["kick-message"]);
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
                $this->pearlTracking[$entity->getId()] = [
                    "player" => $shooter->getName(),
                    "launch_time" => time(),
                    "start_pos" => $shooter->getPosition()
                ];
            }
        }
    }
    
    /**
     * Maneja el impacto de proyectiles
     */
    public function onProjectileHit(ProjectileHitEvent $event): void {
        $entity = $event->getEntity();
        
        if ($entity instanceof EnderPearl) {
            $entityId = $entity->getId();
            
            if (isset($this->pearlTracking[$entityId])) {
                $data = $this->pearlTracking[$entityId];
                $player = $this->getServer()->getPlayerExact($data["player"]);
                
                if ($player !== null && $player->isOnline()) {
                    $hitPos = $entity->getPosition();
                    $distance = $data["start_pos"]->distance($hitPos);
                    
                    // Verificar distancia máxima
                    if ($distance > $this->config->get("max-pearl-distance")) {
                        if ($this->config->get("log-glitches")) {
                            $this->getLogger()->warning("Perla de {$data["player"]} viajó {$distance} bloques (máximo: {$this->config->get("max-pearl-distance")})");
                        }
                        
                        if (!$player->hasPermission("ultimateantiglitch.bypass")) {
                            $player->sendMessage($this->config->get("messages")["glitch-detected"]);
                            
                            if ($this->config->get("kick-on-glitch")) {
                                $this->kickPlayer($player, $this->config->get("messages")["kick-message"]);
                            }
                        }
                    }
                }
                
                unset($this->pearlTracking[$entityId]);
            }
        }
    }
    
    /**
     * Maneja el movimiento de jugadores
     */
    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        $from = $event->getFrom();
        $to = $event->getTo();
        
        // Actualizar posición del jugador
        $this->playerPositions[$playerName] = $to;
        
        // Skip si tiene bypass
        if ($player->hasPermission("ultimateantiglitch.bypass")) {
            return;
        }
        
        // Verificar movimiento sospechoso
        $distance = $from->distance($to);
        
        // Si se movió más de 10 bloques en un solo tick (muy sospechoso)
        if ($distance > 10) {
            if ($this->config->get("log-glitches")) {
                $this->getLogger()->warning("Jugador {$playerName} se movió {$distance} bloques en un tick - posible teletransporte ilegal");
            }
            
            // Cancelar el movimiento y devolver al jugador
            $event->cancel();
            $player->sendMessage($this->config->get("messages")["glitch-detected"]);
            
            if ($this->config->get("kick-on-glitch")) {
                $this->kickPlayer($player, $this->config->get("messages")["kick-message"]);
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
        
        // Skip si tiene bypass
        if ($player->hasPermission("ultimateantiglitch.bypass")) {
            return;
        }
        
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
        
        // Marcar como completado inmediatamente si el evento no fue cancelado
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function() use ($playerName): void {
                if (isset($this->blockBreakData[$playerName])) {
                    $this->blockBreakData[$playerName]["completed"] = true;
                }
            }
        ), 1); // 1 tick después
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
        unset($this->blockBreakData[$playerName]);
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
                
                if ($this->config->get("kick-on-glitch") && !$player->hasPermission("ultimateantiglitch.bypass")) {
                    $this->getScheduler()->scheduleDelayedTask(new ClosureTask(
                        function() use ($player): void {
                            if ($player->isOnline()) {
                                $this->kickPlayer($player, $this->config->get("messages")["kick-message"]);
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
        
        // Verificar movimiento repentino desde la última posición conocida
        $playerName = $player->getName();
        if (isset($this->playerPositions[$playerName])) {
            $lastPosition = $this->playerPositions[$playerName];
            $distance = $position->distance($lastPosition);
            
            // Si se movió más de la distancia máxima permitida desde la última verificación
            if ($distance > $this->config->get("max-pearl-distance")) {
                return true;
            }
        }
        
        return false;
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
    
    /**
     * Limpia datos antiguos para evitar consumo excesivo de memoria
     */
    private function cleanupOldData(): void {
        $currentTime = time();
        
        // Limpiar cooldowns expirados
        foreach ($this->pearlCooldowns as $playerName => $expireTime) {
            if ($currentTime > $expireTime) {
                unset($this->pearlCooldowns[$playerName]);
            }
        }
        
        // Limpiar datos de desconexión antiguos (más de 1 hora)
        foreach ($this->disconnectData as $playerName => $data) {
            if ($currentTime - $data["disconnect_time"] > 3600) {
                unset($this->disconnectData[$playerName]);
            }
        }
        
        // Limpiar tracking de perlas antiguas (más de 30 segundos)
        foreach ($this->pearlTracking as $entityId => $data) {
            if ($currentTime - $data["launch_time"] > 30) {
                unset($this->pearlTracking[$entityId]);
            }
        }
        
        // Limpiar posiciones de jugadores que no están online
        foreach ($this->playerPositions as $playerName => $position) {
            $player = $this->getServer()->getPlayerExact($playerName);
            if ($player === null || !$player->isOnline()) {
                unset($this->playerPositions[$playerName]);
            }
        }
    }
    
    /**
     * Realiza una verificación del jugador
     */
    private function performPlayerCheck(Player $player): void {
        $playerName = $player->getName();
        $messages = [];
        
        $messages[] = "§e=== Verificación de {$playerName} ===";
        
        // Verificar cooldown de perlas
        if (isset($this->pearlCooldowns[$playerName])) {
            $timeLeft = $this->pearlCooldowns[$playerName] - time();
            if ($timeLeft > 0) {
                $messages[] = "§7Cooldown de perla: §c{$timeLeft}s restantes";
            } else {
                $messages[] = "§7Cooldown de perla: §aDisponible";
            }
        } else {
            $messages[] = "§7Cooldown de perla: §aDisponible";
        }
        
        // Verificar posición
        $position = $player->getPosition();
        $messages[] = "§7Posición: §f" . round($position->getX(), 2) . ", " . round($position->getY(), 2) . ", " . round($position->getZ(), 2);
        
        // Verificar si está en posición sospechosa
        if ($this->isPlayerInSuspiciousPosition($player)) {
            $messages[] = "§7Estado: §cPosición sospechosa detectada";
        } else {
            $messages[] = "§7Estado: §aNormal";
        }
        
        // Verificar permisos
        if ($player->hasPermission("ultimateantiglitch.bypass")) {
            $messages[] = "§7Permisos: §aBypass activado";
        } else {
            $messages[] = "§7Permisos: §fNormal";
        }
        
        foreach ($messages as $message) {
            $player->sendMessage($message);
        }
    }
    
    /**
     * Kickea a un jugador de forma segura
     */
    private function kickPlayer(Player $player, string $reason): void {
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function() use ($player, $reason): void {
                if ($player->isOnline()) {
                    $player->kick($reason);
                }
            }
        ), 1); // Kickear en el siguiente tick
    }
}