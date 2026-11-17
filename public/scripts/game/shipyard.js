import { qs, qsa, setVisibleInterval, clearVisibleInterval, now, getRestTimeFormat, pretty_number, pageData } from '../app/core.js';
import { env } from '../app/env.js';

/**
 * Shipyard / defense queue display: replays the build queue from the page-load state plus the elapsed
 * server time (so hidden tabs cannot freeze it) and keeps the cancel list and the timers current.
 * The queue comes from the data island #shipyard-data ({data, bdOperating, bdAvailable}).
 */
const { data, bdOperating, bdAvailable } = pageData('shipyard-data');

let umode = false;
let queueSnapshot = [];
let initialHanger = 0;
let initialAvailable = {};
let lastQueueLength = null;
let lastFirstCount = null;
let lastCompletedById = {};
let ShipyardInterval = null;
let Shipyard = [];
let Amount = null;

/** Seconds since the page was rendered (server time based); 0 in vacation mode, where nothing is produced. */
function pageElapsedSeconds() {
    if (umode) {
        return 0;
    }
    return (now().getTime() - env.startTime) / 1000;
}

/** Takes the queue snapshot from the data island, reads the units already available from the page, renders once and starts the 1 s refresh (not in vacation mode). */
function ShipyardInit() {
    queueSnapshot = data.Queue.map(function (item) {
        return [item[0], item[1], item[2], item[3]];
    });
    initialHanger = data.b_hangar_id_plus;
    let timeleft = qs('#timeleft');
    if (timeleft) {
        timeleft.classList.add('timer');
        timeleft.dataset.time = data.queue_time;
    }
    umode = Number(qs('.timer')?.dataset.umode) === 1;

    for (let i = 0; i < queueSnapshot.length; i++) {
        let elementId = queueSnapshot[i][3];
        if (initialAvailable[elementId] === undefined) {
            let raw = (qs('#val_' + elementId)?.textContent ?? '').replace(/\D/g, '');
            initialAvailable[elementId] = raw === '' ? 0 : parseInt(raw, 10);
        }
    }

    BuildlistShipyard();
    if (document.getElementById('auftr') && !umode) {
        ShipyardInterval = setVisibleInterval(BuildlistShipyard, 1000);
    }
}

// Replay the hangar queue from page-load state plus elapsed server time,
// so hidden-tab ticks cannot freeze or skip finished units.
/**
 * Replays the hangar queue from the page-load state plus the work done since then (seconds), so hidden-tab ticks cannot
 * freeze or skip finished units.
 * @param {number} work production seconds available (initial progress + elapsed time)
 * @returns {{remaining: Array, completedById: Object}} entries left as [name, count, unitTime, elementId, secondsLeftForCurrentUnit],
 *          and the number of units finished per element id
 */
function consumeQueue(work) {
    let remaining = [];
    let completedById = {};

    for (let i = 0; i < queueSnapshot.length; i++) {
        let name = queueSnapshot[i][0];
        let count = Number(queueSnapshot[i][1]);
        let unitTime = Number(queueSnapshot[i][2]);
        let elementId = queueSnapshot[i][3];

        if (unitTime <= 0) {
            completedById[elementId] = (completedById[elementId] || 0) + count;
            continue;
        }

        let batchWork = count * unitTime;
        if (work >= batchWork) {
            work -= batchWork;
            completedById[elementId] = (completedById[elementId] || 0) + count;
            continue;
        }

        let finished = Math.floor(work / unitTime);
        let spentOnCurrent = work - finished * unitTime;
        if (finished > 0) {
            completedById[elementId] = (completedById[elementId] || 0) + finished;
        }
        remaining.push([name, count - finished, unitTime, elementId, unitTime - spentOnCurrent]);
        for (let j = i + 1; j < queueSnapshot.length; j++) {
            remaining.push([
                queueSnapshot[j][0],
                queueSnapshot[j][1],
                queueSnapshot[j][2],
                queueSnapshot[j][3],
                queueSnapshot[j][2]
            ]);
        }
        break;
    }

    return { remaining: remaining, completedById: completedById };
}

/** Updates the "(available: n)" label of each unit type that finished new units (initial count + completed since page load). */
function updateAvailableCounts(completedById) {
    for (let elementId in completedById) {
        if (!Object.prototype.hasOwnProperty.call(completedById, elementId)) {
            continue;
        }
        if (lastCompletedById[elementId] === completedById[elementId]) {
            continue;
        }
        let total = (initialAvailable[elementId] || 0) + completedById[elementId];
        let valBox = qs('#val_' + elementId);
        if (valBox) {
            valBox.textContent = ' (' + bdAvailable + pretty_number(total) + ')';
        }
        lastCompletedById[elementId] = completedById[elementId];
    }
}

/** One refresh: replays the queue for the elapsed time, updates available counts, the queue list and the remaining time of the current batch; shows the ready text and stops when the queue is empty. */
function BuildlistShipyard() {
    let state = consumeQueue(initialHanger + pageElapsedSeconds());
    let listId = document.getElementById('auftr') ? 'auftr' : 'auftrumode';
    let boxId = document.getElementById('auftr') ? 'bx' : 'bxumode';

    Shipyard = [];
    for (let i = 0; i < state.remaining.length; i++) {
        Shipyard.push([
            state.remaining[i][0],
            state.remaining[i][1],
            state.remaining[i][2],
            state.remaining[i][3]
        ]);
    }

    updateAvailableCounts(state.completedById);

    if (state.remaining.length === 0) {
        let emptyBox = document.getElementById(boxId);
        if (emptyBox) {
            emptyBox.innerHTML = env.ready;
        }
        let emptyList = document.getElementById(listId);
        if (emptyList) {
            while (emptyList.length > 0) {
                emptyList.options[emptyList.length - 1] = null;
            }
            emptyList.options[0] = new Option(env.ready);
        }
        if (ShipyardInterval) {
            clearVisibleInterval(ShipyardInterval);
            ShipyardInterval = null;
        }
        return;
    }

    // exact integer part as BigInt (large queue counts keep every digit, like the former bcmath)
    const digits = /^-?\d+/.exec(String(state.remaining[0][1]).trim());
    Amount = digits ? BigInt(digits[0]) : 0n;
    if (lastQueueLength !== state.remaining.length) {
        ShipyardList();
        lastQueueLength = state.remaining.length;
        lastFirstCount = state.remaining[0][1];
    } else if (lastFirstCount !== state.remaining[0][1]) {
        let list = document.getElementById(listId);
        if (list && list.options[0]) {
            list.options[0].innerHTML = Amount.toString() + ' ' + Shipyard[0][0] + ' ' + bdOperating;
        }
        lastFirstCount = state.remaining[0][1];
    }

    let box = document.getElementById(boxId);
    if (box) {
        box.innerHTML = state.remaining[0][0] + ' ' + getRestTimeFormat(state.remaining[0][4]);
    }
}

/** Rebuilds the queue <select> (first entry shows the live count of the current batch). */
function ShipyardList() {
    let list = document.getElementById('auftr') || document.getElementById('auftrumode');
    if (!list) {
        return;
    }
    while (list.length > 0) {
        list.options[list.length - 1] = null;
    }
    for (let iv = 0; iv < Shipyard.length; iv++) {
        if (iv == 0) {
            list.options[iv] = new Option(Amount.toString() + ' ' + Shipyard[iv][0] + ' ' + bdOperating, iv);
        } else {
            list.options[iv] = new Option(Shipyard[iv][1] + ' ' + Shipyard[iv][0] + ' ' + bdOperating, iv);
        }
    }
}

window.onPageInit(function () {
    ShipyardInit();
    setVisibleInterval(function () {
        qsa('.timer').forEach(function (el) {
            let s = Number(el.dataset.time);
            if (!umode) {
                s = s - (now().getTime() - env.startTime) / 1000;
            }
            el.textContent = s <= 0 ? env.ready : getRestTimeFormat(s);
        });
    }, 1000);
});
