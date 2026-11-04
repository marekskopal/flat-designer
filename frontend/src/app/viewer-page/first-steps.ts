import {OpeningType, Scene} from '@app/models/scene';

/** A step of the first-steps guide, which the page starts when its button is pressed. */
export type FirstStep = 'walls' | 'rooms' | 'door' | 'furniture' | 'walk';

export interface StepView {
    key: FirstStep;
    title: string;
    text: string;
    action: string;
    done: boolean;
}

const DoorTypes: readonly OpeningType[] = ['Door', 'DoubleDoor', 'SlidingDoor', 'PocketDoor', 'Passage', 'GarageDoor'];

/** The guide's steps for the floor, each done once the floor has it (the walk once one was taken on the page). */
export const firstSteps = (scene: Scene, walked: boolean): StepView[] => {
    const doors = scene.walls.some((wall) => wall.openings.some((opening) => DoorTypes.includes(opening.type)));
    return [
        {
            key: 'walls',
            title: 'Draw the walls',
            text: 'Click the corners of the centre line in the plan; type 3200 for an exact length. Enter or Esc ends the run.',
            action: 'Draw walls',
            done: scene.walls.length > 0,
        },
        {
            key: 'rooms',
            title: 'Make the rooms',
            text: 'Click inside the walls: the room follows their inner faces and shows its area.',
            action: 'Room from walls',
            done: scene.rooms.length > 0,
        },
        {
            key: 'door',
            title: 'Put in a door',
            text: 'Select a wall, then Add → Door (or a window). Drag it along the wall.',
            action: 'Open Add',
            done: doors,
        },
        {
            key: 'furniture',
            title: 'Furnish it',
            text: 'Add → Furniture: kitchens, beds, sofas, lights. Drag to place, R turns.',
            action: 'Open Add',
            done: scene.items.length > 0,
        },
        {
            key: 'walk',
            title: 'Walk through',
            text: 'W A S D walks, the mouse looks, E opens a door, Esc ends the walk.',
            action: 'Walk',
            done: walked,
        },
    ];
};
