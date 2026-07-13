<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuSeparator,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList, CommandSeparator, CommandShortcut } from '@/components/ui/command';
import { ChevronDown, Tags, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import ResolvedNote from './ResolvedNote.vue';
import SinkSection from './SinkSection.vue';

const deleteOpen = ref(false);
const commandValue = ref('');
</script>

<template>
    <SinkSection id="overlays" title="Overlays">
        <ResolvedNote
            tag="one recipe"
            note="Everything floated shares bg popover · border line-2 · radius md · shadow-overlay. Tooltip is the compact variant. Get help / Support development sit on the standard Popover."
        />
        <div class="flex flex-wrap items-center gap-3">
            <Dialog v-model:open="deleteOpen">
                <DialogTrigger as-child>
                    <Button variant="destructive"><Trash2 class="size-4" /> Delete match</Button>
                </DialogTrigger>
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Delete this match?</DialogTitle>
                        <DialogDescription>This removes it from your tracker. It can't be undone.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" @click="deleteOpen = false">Cancel</Button>
                        <Button variant="destructive" @click="deleteOpen = false">Delete</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Sheet>
                <SheetTrigger as-child>
                    <Button variant="outline">Open matchup detail</Button>
                </SheetTrigger>
                <SheetContent side="right" class="w-[420px] sm:max-w-[420px]">
                    <SheetHeader>
                        <SheetTitle>UW Control</SheetTitle>
                    </SheetHeader>
                    <p class="px-4 text-sm text-muted-foreground">Sheet content slides in from the side — same primitive family as Dialog.</p>
                </SheetContent>
            </Sheet>
        </div>

        <div class="flex flex-wrap items-start gap-6">
            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button variant="outline">Hover for tooltip</Button>
                    </TooltipTrigger>
                    <TooltipContent>Match notes: mull to 5, still won.</TooltipContent>
                </Tooltip>
            </TooltipProvider>

            <HoverCard>
                <HoverCardTrigger as-child>
                    <Button variant="outline">Hover for card</Button>
                </HoverCardTrigger>
                <HoverCardContent class="w-64 text-sm">
                    Richer preview content — used for card art peeks in the 0.x game replay view.
                </HoverCardContent>
            </HoverCard>

            <Popover>
                <PopoverTrigger as-child>
                    <Button variant="outline">Click for popover</Button>
                </PopoverTrigger>
                <PopoverContent class="w-64 text-sm">Click-triggered, stays open until dismissed.</PopoverContent>
            </Popover>
        </div>

        <div class="flex flex-wrap items-start gap-6">
            <ContextMenu>
                <ContextMenuTrigger
                    class="flex h-24 w-56 items-center justify-center rounded-md border border-dashed text-sm text-muted-foreground"
                >
                    Right-click this zone
                </ContextMenuTrigger>
                <ContextMenuContent>
                    <ContextMenuItem><Tags class="size-4" /> Set archetype</ContextMenuItem>
                    <ContextMenuSeparator />
                    <ContextMenuItem variant="destructive"><Trash2 class="size-4" /> Delete</ContextMenuItem>
                </ContextMenuContent>
            </ContextMenu>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button variant="outline">Open dropdown <ChevronDown class="size-4" /></Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                    <DropdownMenuItem><Tags class="size-4" /> Set archetype</DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem variant="destructive"><Trash2 class="size-4" /> Delete</DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <div class="flex flex-col gap-2">
            <h3 class="text-sm font-medium text-muted-foreground">Command palette — ⌘K only</h3>
            <Command v-model="commandValue" class="w-72 rounded-md border border-line-2 bg-popover shadow-overlay">
                <CommandInput placeholder="Type a command…" />
                <CommandList>
                    <CommandEmpty>No results found.</CommandEmpty>
                    <CommandGroup heading="Navigate">
                        <CommandItem value="decks">Go to Decks<CommandShortcut>G D</CommandShortcut></CommandItem>
                        <CommandItem value="leagues">Go to Leagues<CommandShortcut>G L</CommandShortcut></CommandItem>
                    </CommandGroup>
                    <CommandSeparator />
                    <CommandGroup heading="Actions">
                        <CommandItem value="log">Log a match<CommandShortcut>⌘L</CommandShortcut></CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </div>
    </SinkSection>
</template>
