<?php

declare(strict_types = 1);

namespace JavierLeon9966\BlockLagFix;

use muqsit\simplepackethandler\interceptor\IPacketInterceptor;
use muqsit\simplepackethandler\SimplePacketHandler;

use pocketmine\block\tile\Spawnable;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\world\World;

final class BlockLagFix extends PluginBase{

	private IPacketInterceptor $handler;

	/** @phpstan-var \Closure(BlockActorDataPacket, NetworkSession): bool */
	private \Closure $handleBlockActorData;

	/** @phpstan-var \Closure(UpdateBlockPacket, NetworkSession): bool */
	private \Closure $handleUpdateBlock;
	private ?Player $lastPlayer = null;

	/**
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $oldBlocksFullId = [];

	/**
	 * @var CacheableNbt[]
	 * @phpstan-var array<int, CacheableNbt>
	 */
	private array $oldTilesSerializedCompound = [];

	public function onEnable(): void{
		$this->handler = SimplePacketHandler::createInterceptor($this, EventPriority::HIGHEST);

		$this->handleUpdateBlock = function(UpdateBlockPacket $packet, NetworkSession $target): bool{
			if($target->getPlayer() !== $this->lastPlayer){
				return true;
			}
			$blockHash = World::blockHash($packet->blockPosition->getX(), $packet->blockPosition->getY(), $packet->blockPosition->getZ());
			if(RuntimeBlockMapping::getInstance()->fromRuntimeId($packet->blockRuntimeId) !== ($this->oldBlocksFullId[$blockHash] ?? null)){
				return true;
			}
			unset($this->oldBlocksFullId[$blockHash]);
			if(count($this->oldBlocksFullId) === 0){
				if(count($this->oldTilesSerializedCompound) === 0){
					$this->lastPlayer = null;
				}
				$this->handler->unregisterOutgoingInterceptor($this->handleUpdateBlock);
			}
			return false;
		};
		$this->handleBlockActorData = function(BlockActorDataPacket $packet, NetworkSession $target): bool{
			if($target->getPlayer() !== $this->lastPlayer){
				return true;
			}
			$blockHash = World::blockHash($packet->blockPosition->getX(), $packet->blockPosition->getY(), $packet->blockPosition->getZ());
			if($packet->nbt !== ($this->oldTilesSerializedCompound[$blockHash] ?? null)){
				return true;
			}
			unset($this->oldTilesSerializedCompound[$blockHash]);
			if(count($this->oldTilesSerializedCompound) === 0){
				if(count($this->oldBlocksFullId) === 0){
					$this->lastPlayer = null;
				}
				$this->handler->unregisterOutgoingInterceptor($this->handleBlockActorData);
			}
			return false;
		};
		$this->getServer()->getPluginManager()->registerEvent(PlayerInteractEvent::class, function(PlayerInteractEvent $event): void{
			if($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK || !$event->getItem()->canBePlaced()){
				return;
			}
			$this->lastPlayer = $event->getPlayer();
			$clickedBlock = $event->getBlock();
			$replaceBlock = $clickedBlock->getSide($event->getFace());
			$this->oldBlocksFullId = [];
			$this->oldTilesSerializedCompound = [];
			foreach($clickedBlock->getAllSides() as $block){
				$pos = $block->getPosition();
				$posIndex = World::blockHash($pos->x, $pos->y, $pos->z);
				$this->oldBlocksFullId[$posIndex] = $block->getFullId();
				$tile = $pos->getWorld()->getTileAt($pos->x, $pos->y, $pos->z);
				if($tile instanceof Spawnable){
					$this->oldTilesSerializedCompound[$posIndex] = $tile->getSerializedSpawnCompound();
				}
			}
			foreach($replaceBlock->getAllSides() as $block){
				$pos = $block->getPosition();
				$posIndex = World::blockHash($pos->x, $pos->y, $pos->z);
				$this->oldBlocksFullId[$posIndex] = $block->getFullId();
				$tile = $pos->getWorld()->getTileAt($pos->x, $pos->y, $pos->z);
				if($tile instanceof Spawnable){
					$this->oldTilesSerializedCompound[$posIndex] = $tile->getSerializedSpawnCompound();
				}
			}
			$this->handler->interceptOutgoing($this->handleUpdateBlock);
			$this->handler->interceptOutgoing($this->handleBlockActorData);
		}, EventPriority::MONITOR, $this);
		$this->getServer()->getPluginManager()->registerEvent(BlockPlaceEvent::class, function(BlockPlaceEvent $event): void{
			$this->oldBlocksFullId = [];
			$this->oldTilesSerializedCompound = [];
			$this->lastPlayer = null;
			$this->handler->unregisterOutgoingInterceptor($this->handleUpdateBlock);
			$this->handler->unregisterOutgoingInterceptor($this->handleBlockActorData);
		}, EventPriority::MONITOR, $this, true);
	}
}
