import { qs, qsa, setVisibleInterval, clearVisibleInterval, getRestTimeFormat, pretty_number } from '../app/core.js';

var umode = false;
var queueSnapshot = [];
var initialHanger = 0;
var initialAvailable = {};
var lastQueueLength = null;
var lastFirstCount = null;
var lastCompletedById = {};
var ShipyardInterval = null;
var Shipyard = [];
var Amount = null;

function pageElapsedSeconds() {
    if (umode) {
        return 0;
    }
    return (window.serverTime.getTime() - window.startTime) / 1000;
}

function ShipyardInit() {
    queueSnapshot = window.data.Queue.map(function (item) {
        return [item[0], item[1], item[2], item[3]];
    });
    initialHanger = window.data.b_hangar_id_plus;
    var timeleft = qs('#timeleft');
    if (timeleft) {
        timeleft.classList.add('timer');
        timeleft.dataset.time = window.data.queue_time;
    }
    umode = Number(qs('.timer')?.dataset.umode) === 1;

    for (var i = 0; i < queueSnapshot.length; i++) {
        var elementId = queueSnapshot[i][3];
        if (initialAvailable[elementId] === undefined) {
            var raw = (qs('#val_' + elementId)?.textContent ?? '').replace(/\D/g, '');
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
    var remaining = [];
    var completedById = {};

    for (var i = 0; i < queueSnapshot.length; i++) {
        var name = queueSnapshot[i][0];
        var count = Number(queueSnapshot[i][1]);
        var unitTime = Number(queueSnapshot[i][2]);
        var elementId = queueSnapshot[i][3];

        if (unitTime <= 0) {
            completedById[elementId] = (completedById[elementId] || 0) + count;
            continue;
        }

        var batchWork = count * unitTime;
        if (work >= batchWork) {
            work -= batchWork;
            completedById[elementId] = (completedById[elementId] || 0) + count;
            continue;
        }

        var finished = Math.floor(work / unitTime);
        var spentOnCurrent = work - finished * unitTime;
        if (finished > 0) {
            completedById[elementId] = (completedById[elementId] || 0) + finished;
        }
        remaining.push([name, count - finished, unitTime, elementId, unitTime - spentOnCurrent]);
        for (var j = i + 1; j < queueSnapshot.length; j++) {
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
    for (var elementId in completedById) {
        if (!Object.prototype.hasOwnProperty.call(completedById, elementId)) {
            continue;
        }
        if (lastCompletedById[elementId] === completedById[elementId]) {
            continue;
        }
        var total = (initialAvailable[elementId] || 0) + completedById[elementId];
        var valBox = qs('#val_' + elementId);
        if (valBox) {
            valBox.textContent = ' (' + window.bd_available + pretty_number(total) + ')';
        }
        lastCompletedById[elementId] = completedById[elementId];
    }
}

function BuildlistShipyard() {
    var state = consumeQueue(initialHanger + pageElapsedSeconds());
    var listId = document.getElementById('auftr') ? 'auftr' : 'auftrumode';
    var boxId = document.getElementById('auftr') ? 'bx' : 'bxumode';

    Shipyard = [];
    for (var i = 0; i < state.remaining.length; i++) {
        Shipyard.push([
            state.remaining[i][0],
            state.remaining[i][1],
            state.remaining[i][2],
            state.remaining[i][3]
        ]);
    }

    updateAvailableCounts(state.completedById);

    if (state.remaining.length === 0) {
        var emptyBox = document.getElementById(boxId);
        if (emptyBox) {
            emptyBox.innerHTML = window.Ready;
        }
        var emptyList = document.getElementById(listId);
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
        var list = document.getElementById(listId);
        if (list && list.options[0]) {
            list.options[0].innerHTML = Amount.toString() + ' ' + Shipyard[0][0] + ' ' + window.bd_operating;
        }
        lastFirstCount = state.remaining[0][1];
    }

    var box = document.getElementById(boxId);
    if (box) {
        box.innerHTML = state.remaining[0][0] + ' ' + getRestTimeFormat(state.remaining[0][4]);
    }
}

function ShipyardList() {
    var list = document.getElementById('auftr') || document.getElementById('auftrumode');
    if (!list) {
        return;
    }
    while (list.length > 0) {
        list.options[list.length - 1] = null;
    }
    for (var iv = 0; iv < Shipyard.length; iv++) {
        if (iv == 0) {
            list.options[iv] = new Option(Amount.toString() + ' ' + Shipyard[iv][0] + ' ' + window.bd_operating, iv);
        } else {
            list.options[iv] = new Option(Shipyard[iv][1] + ' ' + Shipyard[iv][0] + ' ' + window.bd_operating, iv);
        }
    }
}

Object.assign(window, { ShipyardInit });

window.onPageInit(function () {
    setVisibleInterval(function () {
        qsa('.timer').forEach(function (el) {
            var s = Number(el.dataset.time);
            if (!umode) {
                s = s - (window.serverTime.getTime() - window.startTime) / 1000;
            }
            el.textContent = s <= 0 ? window.Ready : getRestTimeFormat(s);
        });
    }, 1000);
});
