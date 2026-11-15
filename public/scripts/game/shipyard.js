import { qs, qsa, setVisibleInterval, clearVisibleInterval, getRestTimeFormat, pretty_number, pageData } from '../app/core.js';

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

function pageElapsedSeconds() {
    if (umode) {
        return 0;
    }
    return (window.serverTime.getTime() - window.startTime) / 1000;
}

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
            emptyBox.innerHTML = window.Ready;
        }
        let emptyList = document.getElementById(listId);
        if (emptyList) {
            while (emptyList.length > 0) {
                emptyList.options[emptyList.length - 1] = null;
            }
            emptyList.options[0] = new Option(window.Ready);
        }
        if (ShipyardInterval) {
            clearVisibleInterval(ShipyardInterval);
            ShipyardInterval = null;
        }
        return;
    }

    Amount = new window.DecimalNumber(state.remaining[0][1], 0);
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
                s = s - (window.serverTime.getTime() - window.startTime) / 1000;
            }
            el.textContent = s <= 0 ? window.Ready : getRestTimeFormat(s);
        });
    }, 1000);
});
