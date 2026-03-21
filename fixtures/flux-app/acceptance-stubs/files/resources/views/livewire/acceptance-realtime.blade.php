<div>
    <button wire:poll.5s="refresh">
        Refresh
    </button>

    <span wire:stream="feed-count">
        Feed count
    </span>

    <div wire:ref="feed-panel">
        Feed panel
    </div>

    <input type="file" wire:model="photo" />
</div>
