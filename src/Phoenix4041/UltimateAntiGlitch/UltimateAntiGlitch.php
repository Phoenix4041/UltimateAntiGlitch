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
use pocketmine\event\player\PlayerToggleFlightEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerRespawnEvent;
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
    private array $blockBreakData = [];
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
        
        $this->getLogger()->info("UltimateAntiGlitch Plugin by Phoenix4041 - Activado correctamente!");
        
        // Tarea para verificar posiciones cada tick
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(
            function(): void {
                $this->checkAllPlayersPositions();
            }
        ), 1); // Cada tick
        
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
                    $sender->sendMessage("§7Versión: §f1.0.0 (Sin Kicks/Freeze)");
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
     * Maneja el uso de perlas de ender - RESTAURADO
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
            
            // Verificar posición sospechosa antes de permitir el uso
            if ($this->isPlayerInSuspiciousPosition($player)) {
                $event->cancel();
                $player->sendMessage($this->config->get("messages")["pearl-blocked"]);
                $this->addViolation($player, "Pearl en posición sospechosa");
                return;
            }
            
            // Verificar si está en bloques sólidos
            if ($this->isPlayerInsideSolidBlocks($player)) {
                $event->cancel();
                $player->sendMessage("§cNo puedes usar perlas dentro de bloques!");
                $this->addViolation($player, "Pearl dentro de bloques");
                return;
            }
            
            // Establecer cooldown
            $this->pearlCooldowns[$playerName] = time() + $this->config->get("pearl-cooldown");
        }
    }
    
    /**
     * Maneja el lanzamiento de proyectiles - RESTAURADO
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
     * Maneja el impacto de proyectiles - RESTAURADO
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
                    $travelTime = microtime(true) - $data["launch_time"];
                    
                    // Verificar distancia máxima
                    if ($distance > $this->config->get("max-pearl-distance")) {
                        $this->addViolation($player, "Pearl distancia excesiva: {$distance} bloques");
                        
                        // Cancelar el teletransporte devolviendo al jugador a su posición anterior
                        if (isset($this->playerPositions[$data["player"]])) {
                            $player->teleport($this->playerPositions[$data["player"]]);
                            $player->sendMessage("§eHas sido regresado por usar pearl muy lejos.");
                        }
                        return;
                    }
                    
                    // Verificar velocidad sospechosa (muy rápido = posible glitch)
                    if ($travelTime < 0.1 && $distance > 5) {
                        $this->addViolation($player, "Pearl velocidad sospechosa");
                        if (isset($this->playerPositions[$data["player"]])) {
                            $player->teleport($this->playerPositions[$data["player"]]);
                            $player->sendMessage("§eHas sido regresado por velocidad de pearl sospechosa.");
                        }
                        return;
                    }
                    
                    // Verificar si el destino es una posición válida
                    $world = $player->getWorld();
                    $blockAt = $world->getBlock($hitPos);
                    $blockAbove = $world->getBlock($hitPos->add(0, 1, 0));
                    
                    if ($blockAt->isSolid() && $blockAbove->isSolid()) {
                        $this->addViolation($player, "Pearl destino dentro de bloques");
                        if (isset($this->playerPositions[$data["player"]])) {
                            $player->teleport($this->playerPositions[$data["player"]]);
                            $player->sendMessage("§eHas sido regresado por destino de pearl inválido.");
                        }
                        return;
                    }
                }
                
                unset($this->pearlTracking[$entityId]);
            }
        }
    }
    
    /**
     * Maneja el movimiento de jugadores - RESTAURADO
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
        
        // Calcular velocidad
        if (isset($this->playerVelocity[$playerName])) {
            $timeDiff = $currentTime - $this->playerVelocity[$playerName]["time"];
            if ($timeDiff > 0) {
                $velocity = $distance / $timeDiff;
                $this->playerVelocity[$playerName] = [
                    "velocity" => $velocity,
                    "time" => $currentTime,
                    "distance" => $distance
                ];
            }
        } else {
            $this->playerVelocity[$playerName] = [
                "velocity" => $distance * 20, // Aproximación por tick
                "time" => $currentTime,
                "distance" => $distance
            ];
        }
        
        // Detectar teletransporte ilegal (movimiento instantáneo de larga distancia)
        if ($distance > 8.0) { // Distancia sospechosa en un solo movimiento
            $this->addViolation($player, "Movimiento sospechoso: {$distance} bloques");
            $event->cancel();
            
            // Devolver al jugador a su posición anterior conocida
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
            return;
        }
        
        // Detectar velocidad excesiva
        if (isset($this->playerVelocity[$playerName])) {
            $velocity = $this->playerVelocity[$playerName]["velocity"];
            
            // Velocidad máxima permitida (bloques por segundo)
            $maxVelocity = $player->isFlying() ? 20.0 : 12.0;
            
            if ($velocity > $maxVelocity && $distance > 1.0) {
                $this->addViolation($player, "Velocidad excesiva: {$velocity} b/s");
                $event->cancel();
                return;
            }
        }
        
        // Detectar atravesar bloques
        if ($this->isPlayerInsideSolidBlocks($player, $to)) {
            $this->addViolation($player, "Atravesando bloques sólidos");
            $event->cancel();
            return;
        }
        
        // Actualizar posición válida
        $this->playerPositions[$playerName] = $from; // Guardar la posición anterior válida
    }
    
    /**
     * Verificar constantemente las posiciones de todos los jugadores
     */
    private function checkAllPlayersPositions(): void {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $playerName = $player->getName();
            
            if ($player->hasPermission("ultimateantiglitch.bypass")) {
                continue;
            }
            
            // Verificar si está dentro de bloques sólidos
            if ($this->isPlayerInsideSolidBlocks($player)) {
                // Intentar sacarlo de los bloques
                $safePos = $this->findSafePosition($player);
                if ($safePos !== null) {
                    $player->teleport($safePos);
                    $player->sendMessage("§eHas sido movido a una posición segura.");
                } else {
                    $this->addViolation($player, "Stuck dentro de bloques");
                }
            }
            
            // Verificar afk para posibles glitches de desconexión
            if (isset($this->lastMoveTime[$playerName])) {
                $timeSinceMove = microtime(true) - $this->lastMoveTime[$playerName];
                
                // Si está inmóvil por más de 30 segundos mientras rompe bloques
                if ($timeSinceMove > 30 && isset($this->blockBreakData[$playerName])) {
                    $this->addViolation($player, "AFK mientras rompe bloques");
                }
            }
        }
    }
    
    /**
     * Maneja cuando un jugador rompe un bloque - RESTAURADO
     */
    public function onBlockBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $playerName = $player->getName();
        
        if ($player->hasPermission("ultimateantiglitch.bypass")) {
            return;
        }
        
        // Verificar si está demasiado lejos del bloque
        $distance = $player->getPosition()->distance($block->getPosition());
        if ($distance > 6.0) { // Rango máximo de rotura
            $event->cancel();
            $this->addViolation($player, "Rompiendo bloque muy lejos: {$distance} bloques");
            return;
        }
        
        // Registrar el intento de rotura
        $this->blockBreakData[$playerName] = [
            "position" => $block->getPosition(),
            "time" => microtime(true),
            "completed" => false,
            "block_type" => $block->getTypeId()
        ];
        
        // Verificar después de un tiempo si se completó
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function() use ($playerName): void {
                $this->checkBlockBreakCompletion($playerName);
            }
        ), 40); // 2 segundos
    }
    
    /**
     * Maneja cuando un jugador se desconecta - RESTAURADO
     */
    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        // Si estaba rompiendo un bloque, es sospechoso
        if (isset($this->blockBreakData[$playerName]) && !$this->blockBreakData[$playerName]["completed"]) {
            $this->disconnectData[$playerName] = [
                "disconnect_time" => time(),
                "block_position" => $this->blockBreakData[$playerName]["position"],
                "suspicious" => true,
                "violations" => $this->violationCount[$playerName] ?? 0
            ];
            
            if ($this->config->get("log-glitches")) {
                $this->getLogger()->warning("Jugador {$playerName} se desconectó mientras rompía un bloque - GLITCH DE DESCONEXIÓN DETECTADO");
            }
        }
        
        // Limpiar datos del jugador
        unset($this->pearlCooldowns[$playerName]);
        unset($this->playerPositions[$playerName]);
        unset($this->blockBreakData[$playerName]);
        unset($this->playerVelocity[$playerName]);
        unset($this->lastMoveTime[$playerName]);
    }
    
    /**
     * Maneja cuando un jugador se conecta - SIN PENALIZACIONES DE CONGELAMIENTO
     */
    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        // Verificar si había usado glitch de desconexión - SOLO ADVERTENCIA
        if (isset($this->disconnectData[$playerName]) && $this->disconnectData[$playerName]["suspicious"]) {
            $timeSinceDisconnect = time() - $this->disconnectData[$playerName]["disconnect_time"];
            
            // Si se reconectó dentro de 5 minutos, solo advertir
            if ($timeSinceDisconnect < 300) {
                $player->sendMessage("§eAdvertencia: Se detectó una desconexión sospechosa anterior.");
                
                if ($this->config->get("log-glitches")) {
                    $this->getLogger()->warning("Jugador {$playerName} se reconectó después de glitch de desconexión");
                }
            }
            
            unset($this->disconnectData[$playerName]);
        }
        
        // Inicializar datos del jugador
        $this->playerPositions[$playerName] = $player->getPosition();
        $this->lastMoveTime[$playerName] = microtime(true);
        $this->violationCount[$playerName] = $this->violationCount[$playerName] ?? 0;
    }
    
    /**
     * Verifica si un jugador está dentro de bloques sólidos - CORREGIDO
     */
    private function isPlayerInsideSolidBlocks(Player $player, ?Position $pos = null): bool {
        $position = $pos ?? $player->getPosition();
        $world = $position->getWorld();
        
        // Verificar múltiples puntos del jugador (cabeza, cuerpo, pies)
        $positions = [
            $position, // Pies
            $position->add(0, 1, 0), // Cuerpo
            $position->add(0, 1.8, 0) // Cabeza
        ];
        
        foreach ($positions as $checkPos) {
            $block = $world->getBlock($checkPos);
            
            // Usar getTypeId() para comparar bloques en lugar de equals()
            if ($block->isSolid() && 
                $block->getTypeId() !== VanillaBlocks::WATER()->getTypeId() && 
                $block->getTypeId() !== VanillaBlocks::LAVA()->getTypeId()) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Verifica si un jugador está en una posición sospechosa - RESTAURADO
     */
    private function isPlayerInSuspiciousPosition(Player $player): bool {
        $position = $player->getPosition();
        
        // Verificar si está dentro de bloques sólidos
        if ($this->isPlayerInsideSolidBlocks($player)) {
            return true;
        }
        
        // Verificar movimiento desde la última posición
        $playerName = $player->getName();
        if (isset($this->playerPositions[$playerName])) {
            $lastPosition = $this->playerPositions[$playerName];
            $distance = $position->distance($lastPosition);
            
            // Si se movió demasiado rápido
            if ($distance > 15.0) {
                return true;
            }
        }
        
        // Verificar si está volando sin permisos
        if ($player->isFlying() && !$player->getAllowFlight() && !$player->hasPermission("ultimateantiglitch.bypass")) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Busca una posición segura para el jugador
     */
    private function findSafePosition(Player $player): ?Position {
        $world = $player->getWorld();
        $currentPos = $player->getPosition();
        
        // Buscar hacia arriba
        for ($y = $currentPos->getY(); $y < $world->getMaxY(); $y++) {
            $checkPos = new Position($currentPos->getX(), $y, $currentPos->getZ(), $world);
            
            if (!$this->isPlayerInsideSolidBlocks($player, $checkPos)) {
                return $checkPos;
            }
        }
        
        // Si no encuentra posición segura, usar spawn
        return $world->getSpawnLocation();
    }
    
    /**
     * Verifica la finalización de rotura de bloques
     */
    private function checkBlockBreakCompletion(string $playerName): void {
        if (!isset($this->blockBreakData[$playerName])) {
            return;
        }
        
        $data = $this->blockBreakData[$playerName];
        $player = $this->getServer()->getPlayerExact($playerName);
        
        if ($player === null || !$player->isOnline()) {
            // Jugador desconectado = glitch de desconexión
            if ($this->config->get("log-glitches")) {
                $this->getLogger()->warning("GLITCH DE DESCONEXIÓN: {$playerName} se desconectó mientras rompía bloque");
            }
            unset($this->blockBreakData[$playerName]);
            return;
        }
        
        // Verificar si el bloque aún existe
        $world = $data["position"]->getWorld();
        $block = $world->getBlock($data["position"]);
        
        if ($block->getTypeId() === $data["block_type"]) {
            // El bloque no se rompió = posible glitch
            $this->addViolation($player, "Bloque no se rompió después de tiempo límite");
        }
        
        unset($this->blockBreakData[$playerName]);
    }
    
    /**
     * Agrega una violación al jugador - SIN CONGELAMIENTO NI KICK
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
        
        // Solo advertencias, sin penalizaciones severas
        if ($violations >= 5) {
            $player->sendMessage("§cAdvertencia severa: Se detectó actividad sospechosa repetida.");
        } elseif ($violations >= 3) {
            $player->sendMessage("§eAdvertencia: Múltiples violaciones detectadas. Violación: {$reason}");
        } else {
            $player->sendMessage("§eAdvertencia: {$reason}");
        }
        
        // NO más congelamiento ni kicks
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
        
        // Reducir violaciones cada hora
        foreach ($this->violationCount as $playerName => $count) {
            if ($count > 0) {
                $this->violationCount[$playerName] = max(0, $count - 1);
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
        
        // Verificar si está en posición sospechosa
        if ($this->isPlayerInSuspiciousPosition($player)) {
            $messages[] = "§7Posición: §cSospechosa";
        } else {
            $messages[] = "§7Posición: §aNormal";
        }
        
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