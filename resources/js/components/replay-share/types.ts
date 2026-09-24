/** What the replay page knows about sharing this game. */
export type ReplayShareState = {
    /** Signed in to mymtgo.com on this device. */
    linked: boolean;
    /** The tier the server last reported; unknown reads as false. */
    supporter: boolean;
    /** The shared link, or null when this game has not been shared. */
    url: string | null;
};
