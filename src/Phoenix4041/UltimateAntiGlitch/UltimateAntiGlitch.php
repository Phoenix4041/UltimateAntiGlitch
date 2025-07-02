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
use pocketmine\math\Vector3;
use pocketmine\block\VanillaBlocks;

class UltimateAntiGlitch extends PluginBase implements Listener {
    
    private array $pearlCooldowns = [];
    private array $playerPositions = [];
    private array $disconnectData = [];
    private array $pearlTracking = [];
    private array $playerVelocity = [];
    private array $lastMoveTime = [];
    private array $violationCount = [];
    private Config $config;
    
    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->config = $this->getConfig();
        
        $this->getLogger()->info("UltimateAntiGlitch Plugin by Phoenix4041 - Activado (Modo Suave)!");
        
        // Tarea para limpiar datos antiguos cada 5 minutos
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(
            function(): void {
                $this->cleanupOldData();
            }
        ), 6000);
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
                    $sender->sendMessage("§7Versión: §f1.0.0 (Modo Suave)");
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
     * Maneja el uso de perlas de ender - SIMPLIFICADO
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
            
            // Verificar cooldown solamente
            if (isset($this->pearlCooldowns[$playerName])) {
                $timeLeft = $this->pearlCooldowns[$playerName] - time();
                if ($timeLeft > 0) {
                    $event->cancel();
                    $player->sendMessage($this->config->get("messages")["pearl-cooldown"]);
                    return;
                }
            }
            
            // Establecer cooldown
            $this->pearlCooldowns[$playerName] = time() + $this->config->get("pearl-cooldown");
        }
    }
    
    /**
     * Maneja el lanzamiento de proyectiles
     */
    public function onProjectileLaunch(ProjectileLaunchEvent $event): void {
        $entity = $event->getEntity();
        
        if ($entity instanceof EnderPearl) {
            $shooter = $entity->getOwningEntity();
            
            if ($shooter instanceof Player) {
                $playerName = $shooter->getName();
                
                $this->pearlTracking[$entity->getId()] = [
                    "player" => $playerName,
                    "launch_time" => microtime(true),
                    "start_pos" => $shooter->getPosition()->asVector3(),
                    "entity" => $entity
                ];
            }
        }
    }
    
    /**
     * Maneja el impacto de proyectiles - SIMPLIFICADO
     */
    public function onProjectileHit(ProjectileHitEvent $event): void {
        $entity = $event->getEntity();
        
        if ($entity instanceof EnderPearl) {
            $entityId = $entity->getId();
            
            if (isset($this->pearlTracking[$entityId])) {
                $data = $this->pearlTracking[$entityId];
                $player = $this->getServer()->getPlayerExact($data["player"]);
                
                if ($player !== null && $player->isOnline()) {
                    $hitPos = $entity->getPosition()->asVector3();
                    $distance = $data["start_pos"]->distance($hitPos);
                    
                    // Solo verificar distancia extrema (más permisivo)
                    if ($distance > ($this->config->get("max-pearl-distance") * 2)) { // Doubled the limit
                        $this->addViolation($player, "Pearl distancia muy excesiva: {$distance} bloques");
                        
                        // Solo regresar al jugador sin más penalizaciones
                        if (isset($this->playerPositions[$data["player"]])) {
                            $player->teleport($this->playerPositions[$data["player"]]);
                            $player->sendMessage("§eHas sido regresado por usar pearl muy lejos.");
                        }
                        return;
                    }
                }
                
                unset($this->pearlTracking[$entityId]);
            }
        }
    }
    
    /**
     * Maneja el movimiento de jugadores - MUY SIMPLIFICADO
     */
    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        $from = $event->getFrom();
        $to = $event->getTo();
        
        // Skip si tiene bypass
        if ($player->hasPermission("ultimateantiglitch.bypass")) {
            $this->playerPositions[$playerName] = $to;
            return;
        }
        
        $currentTime = microtime(true);
        $distance = $from->distance($to);
        
        // Actualizar tiempo del último movimiento
        $this->lastMoveTime[$playerName] = $currentTime;
        
        // Solo detectar teletransporte EXTREMO (muy permisivo)
        if ($distance > 25.0) { // Aumentado de 8 a 25 bloques
            $this->addViolation($player, "Teletransporte extremo: {$distance} bloques");
            
            // Solo regresar al jugador
            if (isset($this->playerPositions[$playerName])) {
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(
                    function() use ($player, $playerName): void {
                        if ($player->isOnline() && isset($this->playerPositions[$playerName])) {
                            $player->teleport($this->playerPositions[$playerName]);
                            $player->sendMessage("§eHas sido regresado por movimiento sospechoso.");
                        }
                    }
                ), 1);
            }
            $event->cancel();
            return;
        }
        
        // Actualizar posición válida
        $this->playerPositions[$playerName] = $from;
    }
    
    /**
     * Maneja cuando un jugador rompe un bloque - ELIMINADAS RESTRICCIONES AGRESIVAS
     */
    public function onBlockBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $playerName = $player->getName();
        
        if ($player->hasPermission("ultimateantiglitch.bypass")) {
            return;
        }
        
        // Solo verificar distancia extrema
        $distance = $player->getPosition()->distance($block->getPosition());
        if ($distance > 10.0) { // Aumentado de 6 a 10 bloques
            $event->cancel();
            $player->sendMessage("§eEstás muy lejos para romper ese bloque.");
            return;
        }
        
        // No más verificaciones agresivas de rotura de bloques
    }
    
    /**
     * Maneja cuando un jugador se desconecta - SIMPLIFICADO
     */
    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        // Solo limpiar datos, sin penalizaciones
        unset($this->pearlCooldowns[$playerName]);
        unset($this->playerPositions[$playerName]);
        unset($this->playerVelocity[$playerName]);
        unset($this->lastMoveTime[$playerName]);
    }
    
    /**
     * Maneja cuando un jugador se conecta - SIN PENALIZACIONES
     */
    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        // Solo inicializar datos
        $this->playerPositions[$playerName] = $player->getPosition();
        $this->lastMoveTime[$playerName] = microtime(true);
        $this->violationCount[$playerName] = $this->violationCount[$playerName] ?? 0;
    }
    
    /**
     * Agrega una violación al jugador - SIN PENALIZACIONES SEVERAS
     */
    private function addViolation(Player $player, string $reason): void {
        $playerName = $player->getName();
        
        if (!isset($this->violationCount[$playerName])) {
            $this->violationCount[$playerName] = 0;
        }
        
        $this->violationCount[$playerName]++;
        $violations = $this->violationCount[$playerName];
        
        if ($this->config->get("log-glitches")) {
            $this->getLogger()->warning("VIOLACIÓN #{$violations} - {$playerName}: {$reason}");
        }
        
        // Solo advertencias suaves, sin congelar ni kickear
        if ($violations >= 5) {
            $player->sendMessage("§6Advertencia: Se detectó actividad inusual. Evita usar glitches.");
        } elseif ($violations >= 3) {
            $player->sendMessage("§eAdvertencia: Movimiento sospechoso detectado.");
        }
        
        // No más penalizaciones severas
    }
    
    /**
     * Limpia datos antiguos
     */
    private function cleanupOldData(): void {
        $currentTime = time();
        
        // Limpiar cooldowns expirados
        foreach ($this->pearlCooldowns as $playerName => $expireTime) {
            if ($currentTime > $expireTime) {
                unset($this->pearlCooldowns[$playerName]);
            }
        }
        
        // Limpiar datos de desconexión antiguos
        foreach ($this->disconnectData as $playerName => $data) {
            if ($currentTime - $data["disconnect_time"] > 3600) {
                unset($this->disconnectData[$playerName]);
            }
        }
        
        // Limpiar tracking de perlas antiguas
        foreach ($this->pearlTracking as $entityId => $data) {
            if (microtime(true) - $data["launch_time"] > 30) {
                unset($this->pearlTracking[$entityId]);
            }
        }
        
        // Reducir violaciones más rápido (cada limpieza)
        foreach ($this->violationCount as $playerName => $count) {
            if ($count > 0) {
                $this->violationCount[$playerName] = max(0, $count - 2); // Reducir más rápido
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
        
        // Verificar violaciones
        $violations = $this->violationCount[$playerName] ?? 0;
        $messages[] = "§7Violaciones: §f{$violations}";
        
        // Verificar posición
        $position = $player->getPosition();
        $messages[] = "§7Posición: §f" . round($position->getX(), 2) . ", " . round($position->getY(), 2) . ", " . round($position->getZ(), 2);
        
        // Verificar velocidad actual
        if (isset($this->playerVelocity[$playerName])) {
            $velocity = round($this->playerVelocity[$playerName]["velocity"], 2);
            $messages[] = "§7Velocidad: §f{$velocity} b/s";
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
}