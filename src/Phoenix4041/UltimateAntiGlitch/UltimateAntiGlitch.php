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
    private array $safePositions = []; // Almacena posiciones seguras conocidas
    private array $pearlStartPositions = []; // Almacena posiciones donde empezó el uso de pearl
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
                    $sender->sendMessage("§7Versión: §f1.0.1 (Sin Logs de Velocidad)");
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
     * Maneja el uso de perlas de ender - MEJORADO
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
            
            // Guardar posición exacta donde empezó a usar la pearl
            $this->pearlStartPositions[$playerName] = $player->getPosition();
            
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
     * Maneja el impacto de proyectiles - MEJORADO
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
                    $playerName = $data["player"];
                    
                    // Verificar distancia máxima
                    if ($distance > $this->config->get("max-pearl-distance")) {
                        $this->addViolation($player, "Pearl distancia excesiva: {$distance} bloques");
                        
                        // Regresar a la posición exacta donde empezó la pearl
                        if (isset($this->pearlStartPositions[$playerName])) {
                            $player->teleport($this->pearlStartPositions[$playerName]);
                            $player->sendMessage("§eHas sido regresado al punto donde empezaste a usar la pearl.");
                        }
                        return;
                    }
                    
                    // Verificar velocidad sospechosa
                    if ($travelTime < 0.1 && $distance > 5) {
                        $this->addViolation($player, "Pearl velocidad sospechosa");
                        
                        // Regresar a la posición exacta donde empezó la pearl
                        if (isset($this->pearlStartPositions[$playerName])) {
                            $player->teleport($this->pearlStartPositions[$playerName]);
                            $player->sendMessage("§eHas sido regresado al punto donde empezaste a usar la pearl.");
                        }
                        return;
                    }
                    
                    // Verificar si el destino es una posición válida
                    $world = $player->getWorld();
                    $blockAt = $world->getBlock($hitPos);
                    $blockAbove = $world->getBlock($hitPos->add(0, 1, 0));
                    
                    if ($blockAt->isSolid() && $blockAbove->isSolid()) {
                        $this->addViolation($player, "Pearl destino dentro de bloques");
                        
                        // Regresar a la posición exacta donde empezó la pearl
                        if (isset($this->pearlStartPositions[$playerName])) {
                            $player->teleport($this->pearlStartPositions[$playerName]);
                            $player->sendMessage("§eHas sido regresado al punto donde empezaste a usar la pearl.");
                        }
                        return;
                    }
                }
                
                unset($this->pearlTracking[$entityId]);
            }
        }
    }
    
    /**
     * Maneja el movimiento de jugadores - MEJORADO SIN LOGS DE VELOCIDAD
     */
    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        $from = $event->getFrom();
        $to = $event->getTo();
        
        // Skip si tiene bypass
        if ($player->hasPermission("ultimateantiglitch.bypass")) {
            $this->playerPositions[$playerName] = $to;
            $this->safePositions[$playerName] = $to; // Actualizar posición segura
            return;
        }
        
        $currentTime = microtime(true);
        $distance = $from->distance($to);
        
        // Actualizar tiempo del último movimiento
        $this->lastMoveTime[$playerName] = $currentTime;
        
        // Actualizar posición segura solo si el movimiento es normal
        if ($distance < 2.0 && !$this->isPlayerInsideSolidBlocks($player, $to)) {
            $this->safePositions[$playerName] = $from;
        }
        
        // Calcular velocidad sin logs
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
                "velocity" => $distance * 20,
                "time" => $currentTime,
                "distance" => $distance
            ];
        }
        
// Detectar velocidad excesiva SIN LOGS (ignorar movimientos verticales normales)
        if (isset($this->playerVelocity[$playerName])) {
            $velocity = $this->playerVelocity[$playerName]["velocity"];
            $maxVelocity = $player->isFlying() ? 20.0 : 15.0;
            
            // Calcular distancia horizontal para ignorar saltos/losas
            $horizontalDistance = sqrt(pow($to->getX() - $from->getX(), 2) + pow($to->getZ() - $from->getZ(), 2));
            
            if ($velocity > $maxVelocity && $horizontalDistance > 2.0) {
                $this->addViolation($player, "Velocidad excesiva");
                $event->cancel();
                
                // Regresar a la posición segura exacta
                $this->returnToSafePosition($player);
                return;
            }
        }
        
        // Detectar atravesar bloques
        if ($this->isPlayerInsideSolidBlocks($player, $to)) {
            $this->addViolation($player, "Atravesando bloques sólidos");
            $event->cancel();
            
            // Regresar a la posición segura exacta
            $this->returnToSafePosition($player);
            return;
        }
        
        // Actualizar posición válida
        $this->playerPositions[$playerName] = $from;
    }
    
    /**
     * Regresa al jugador a su posición segura exacta
     */
    private function returnToSafePosition(Player $player): void {
        $playerName = $player->getName();
        
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function() use ($player, $playerName): void {
                if ($player->isOnline()) {
                    $safePos = null;
                    
                    // Prioridad 1: Posición segura registrada
                    if (isset($this->safePositions[$playerName])) {
                        $safePos = $this->safePositions[$playerName];
                    }
                    // Prioridad 2: Posición anterior conocida
                    elseif (isset($this->playerPositions[$playerName])) {
                        $safePos = $this->playerPositions[$playerName];
                    }
                    // Prioridad 3: Posición de inicio de pearl
                    elseif (isset($this->pearlStartPositions[$playerName])) {
                        $safePos = $this->pearlStartPositions[$playerName];
                    }
                    // Último recurso: Spawn
                    else {
                        $safePos = $player->getWorld()->getSpawnLocation();
                    }
                    
                    $player->teleport($safePos);
                    $player->sendMessage("§eHas sido regresado a tu posición segura.");
                }
            }
        ), 1);
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
                $this->addViolation($player, "Stuck dentro de bloques");
                $this->returnToSafePosition($player);
            }
            
            // Verificar afk para posibles glitches de desconexión
            if (isset($this->lastMoveTime[$playerName])) {
                $timeSinceMove = microtime(true) - $this->lastMoveTime[$playerName];
                
                if ($timeSinceMove > 30 && isset($this->blockBreakData[$playerName])) {
                    $this->addViolation($player, "AFK mientras rompe bloques");
                }
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
        
        if ($player->hasPermission("ultimateantiglitch.bypass")) {
            return;
        }
        
        // Verificar si está demasiado lejos del bloque
        $distance = $player->getPosition()->distance($block->getPosition());
        if ($distance > 6.0) {
            $event->cancel();
            $this->addViolation($player, "Rompiendo bloque muy lejos");
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
        ), 40);
    }
    
    /**
     * Maneja cuando un jugador se desconecta
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
        unset($this->safePositions[$playerName]);
        unset($this->pearlStartPositions[$playerName]);
    }
    
    /**
     * Maneja cuando un jugador se conecta
     */
    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        // Verificar si había usado glitch de desconexión
        if (isset($this->disconnectData[$playerName]) && $this->disconnectData[$playerName]["suspicious"]) {
            $timeSinceDisconnect = time() - $this->disconnectData[$playerName]["disconnect_time"];
            
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
        $this->safePositions[$playerName] = $player->getPosition();
        $this->lastMoveTime[$playerName] = microtime(true);
        $this->violationCount[$playerName] = $this->violationCount[$playerName] ?? 0;
    }
    
    /**
     * Verifica si un jugador está dentro de bloques sólidos
     */
    private function isPlayerInsideSolidBlocks(Player $player, ?Position $pos = null): bool {
        $position = $pos ?? $player->getPosition();
        $world = $position->getWorld();
        
        $positions = [
            $position,
            $position->add(0, 1, 0),
            $position->add(0, 1.8, 0)
        ];
        
        foreach ($positions as $checkPos) {
            $block = $world->getBlock($checkPos);
            
            if ($block->isSolid() && 
                $block->getTypeId() !== VanillaBlocks::WATER()->getTypeId() && 
                $block->getTypeId() !== VanillaBlocks::LAVA()->getTypeId()) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Verifica si un jugador está en una posición sospechosa
     */
    private function isPlayerInSuspiciousPosition(Player $player): bool {
        $position = $player->getPosition();
        
        if ($this->isPlayerInsideSolidBlocks($player)) {
            return true;
        }
        
        $playerName = $player->getName();
        if (isset($this->playerPositions[$playerName])) {
            $lastPosition = $this->playerPositions[$playerName];
            $distance = $position->distance($lastPosition);
            
            if ($distance > 15.0) {
                return true;
            }
        }
        
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
        
        for ($y = $currentPos->getY(); $y < $world->getMaxY(); $y++) {
            $checkPos = new Position($currentPos->getX(), $y, $currentPos->getZ(), $world);
            
            if (!$this->isPlayerInsideSolidBlocks($player, $checkPos)) {
                return $checkPos;
            }
        }
        
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
            if ($this->config->get("log-glitches")) {
                $this->getLogger()->warning("GLITCH DE DESCONEXIÓN: {$playerName} se desconectó mientras rompía bloque");
            }
            unset($this->blockBreakData[$playerName]);
            return;
        }
        
        $world = $data["position"]->getWorld();
        $block = $world->getBlock($data["position"]);
        
        if ($block->getTypeId() === $data["block_type"]) {
            $this->addViolation($player, "Bloque no se rompió después de tiempo límite");
        }
        
        unset($this->blockBreakData[$playerName]);
    }
    
    /**
     * Agrega una violación al jugador - SIN LOGS DE VELOCIDAD
     */
    private function addViolation(Player $player, string $reason): void {
        $playerName = $player->getName();
        
        if (!isset($this->violationCount[$playerName])) {
            $this->violationCount[$playerName] = 0;
        }
        
        $this->violationCount[$playerName]++;
        $violations = $this->violationCount[$playerName];
        
        // Solo logear si no es una violación de velocidad
        if ($this->config->get("log-glitches") && !str_contains(strtolower($reason), "velocidad")) {
            $this->getLogger()->warning("VIOLACIÓN #{$violations} - {$playerName}: {$reason}");
        }
        
        // Solo advertencias, sin penalizaciones severas
        if ($violations >= 5) {
            $player->sendMessage("§cAdvertencia severa: Se detectó actividad sospechosa repetida.");
        } elseif ($violations >= 3) {
            $player->sendMessage("§eAdvertencia: Múltiples violaciones detectadas.");
        } else {
            if (!str_contains(strtolower($reason), "velocidad")) {
                $player->sendMessage("§eAdvertencia: Actividad sospechosa detectada.");
            }
        }
    }
    
    /**
     * Limpia datos antiguos
     */
    private function cleanupOldData(): void {
        $currentTime = time();
        
        foreach ($this->pearlCooldowns as $playerName => $expireTime) {
            if ($currentTime > $expireTime) {
                unset($this->pearlCooldowns[$playerName]);
            }
        }
        
        foreach ($this->disconnectData as $playerName => $data) {
            if ($currentTime - $data["disconnect_time"] > 3600) {
                unset($this->disconnectData[$playerName]);
            }
        }
        
        foreach ($this->pearlTracking as $entityId => $data) {
            if (microtime(true) - $data["launch_time"] > 30) {
                unset($this->pearlTracking[$entityId]);
            }
        }
        
        // Limpiar posiciones de pearl antiguas
        foreach ($this->pearlStartPositions as $playerName => $position) {
            $player = $this->getServer()->getPlayerExact($playerName);
            if ($player === null || !$player->isOnline()) {
                unset($this->pearlStartPositions[$playerName]);
            }
        }
        
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
        
        $violations = $this->violationCount[$playerName] ?? 0;
        $messages[] = "§7Violaciones: §f{$violations}";
        
        $position = $player->getPosition();
        $messages[] = "§7Posición: §f" . round($position->getX(), 2) . ", " . round($position->getY(), 2) . ", " . round($position->getZ(), 2);
        
        if ($this->isPlayerInSuspiciousPosition($player)) {
            $messages[] = "§7Posición: §cSospechosa";
        } else {
            $messages[] = "§7Posición: §aNormal";
        }
        
        if ($player->hasPermission("ultimateantiglitch.bypass")) {
            $messages[] = "§7Permisos: §aBypass activado";
        } else {
            $messages[] = "§7Permisos: §fNormal";
        }
        
        // Mostrar posición segura registrada
        if (isset($this->safePositions[$playerName])) {
            $safePos = $this->safePositions[$playerName];
            $messages[] = "§7Posición segura: §f" . round($safePos->getX(), 2) . ", " . round($safePos->getY(), 2) . ", " . round($safePos->getZ(), 2);
        }
        
        foreach ($messages as $message) {
            $player->sendMessage($message);
        }
    }
}