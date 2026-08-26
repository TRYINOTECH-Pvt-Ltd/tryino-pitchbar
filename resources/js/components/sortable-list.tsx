import {
    closestCenter,
    DndContext,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { DragEndEvent } from '@dnd-kit/core';
import {
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical } from 'lucide-react';
import type { ReactNode } from 'react';
import { useT } from '@/lib/i18n';

type Props<T> = {
    items: T[];
    keyOf: (item: T) => string;
    onReorder: (orderedIds: string[]) => void;
    children: (item: T) => ReactNode;
};

export function SortableList<T>({
    items,
    keyOf,
    onReorder,
    children,
}: Props<T>) {
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    const handle = (event: DragEndEvent) => {
        const { active, over } = event;

        if (!over || active.id === over.id) {
            return;
        }

        const oldIndex = items.findIndex((i) => keyOf(i) === active.id);
        const newIndex = items.findIndex((i) => keyOf(i) === over.id);

        if (oldIndex < 0 || newIndex < 0) {
            return;
        }

        const next = arrayMove(items, oldIndex, newIndex).map(keyOf);
        onReorder(next);
    };

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            onDragEnd={handle}
        >
            <SortableContext
                items={items.map(keyOf)}
                strategy={verticalListSortingStrategy}
            >
                <ul className="divide-y">
                    {items.map((item) => (
                        <SortableItem key={keyOf(item)} id={keyOf(item)}>
                            {children(item)}
                        </SortableItem>
                    ))}
                </ul>
            </SortableContext>
        </DndContext>
    );
}

function SortableItem({ id, children }: { id: string; children: ReactNode }) {
    const { t } = useT();
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id });
    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.6 : 1,
    };

    return (
        <li
            ref={setNodeRef}
            style={style}
            className="flex items-start gap-2 py-2"
        >
            <button
                type="button"
                className="mt-1 cursor-grab text-muted-foreground hover:text-foreground active:cursor-grabbing"
                aria-label={t('Drag to reorder')}
                {...attributes}
                {...listeners}
            >
                <GripVertical className="size-4" />
            </button>
            <div className="min-w-0 flex-1">{children}</div>
        </li>
    );
}
