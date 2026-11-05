import {OpeningType, Scene} from '@app/models/scene';

/** A step of the first-steps guide, which the page starts when its button is pressed. */
export type FirstStep = 'walls' | 'rooms' | 'door' | 'furniture' | 'walk';

/** A step as the guide shows it; `title`, `text` and `action` are translation keys. */
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
            title: 'viewer.firstSteps.walls.title',
            text: 'viewer.firstSteps.walls.text',
            action: 'viewer.firstSteps.walls.action',
            done: scene.walls.length > 0,
        },
        {
            key: 'rooms',
            title: 'viewer.firstSteps.rooms.title',
            text: 'viewer.firstSteps.rooms.text',
            action: 'viewer.firstSteps.rooms.action',
            done: scene.rooms.length > 0,
        },
        {
            key: 'door',
            title: 'viewer.firstSteps.door.title',
            text: 'viewer.firstSteps.door.text',
            action: 'viewer.firstSteps.openAdd',
            done: doors,
        },
        {
            key: 'furniture',
            title: 'viewer.firstSteps.furniture.title',
            text: 'viewer.firstSteps.furniture.text',
            action: 'viewer.firstSteps.openAdd',
            done: scene.items.length > 0,
        },
        {
            key: 'walk',
            title: 'viewer.firstSteps.walk.title',
            text: 'viewer.firstSteps.walk.text',
            action: 'viewer.firstSteps.walk.action',
            done: walked,
        },
    ];
};
