// audio.js
// Synthesized Sound Effects and Music using Web Audio API

class MainkuizAudio {
    constructor() {
        this.ctx = null;
        this.lobbyInterval = null;
        this.questionInterval = null;
        this.isMuted = false;
        this.lobbyTempo = 110; // BPM
        this.questionTempo = 135; // BPM
        this.lobbySequence = [
            // [note, duration_beats]
            // Chord Progression: Am - F - C - G
            ['A3', 1], ['E4', 1], ['A4', 1], ['B4', 1],
            ['F3', 1], ['C4', 1], ['F4', 1], ['A4', 1],
            ['C3', 1], ['G3', 1], ['C4', 1], ['E4', 1],
            ['G3', 1], ['D4', 1], ['G4', 1], ['B4', 1]
        ];
        this.noteFreqs = {
            'C3': 130.81, 'D3': 146.83, 'E3': 164.81, 'F3': 174.61, 'G3': 196.00, 'A3': 220.00, 'B3': 246.94,
            'C4': 261.63, 'D4': 293.66, 'E4': 329.63, 'F4': 349.23, 'G4': 392.00, 'A4': 440.00, 'B4': 493.88,
            'C5': 523.25, 'E5': 659.25, 'G5': 783.99, 'C6': 1046.50
        };
    }

    init() {
        if (!this.ctx) {
            this.ctx = new (window.AudioContext || window.webkitAudioContext)();
        }
        if (this.ctx.state === 'suspended') {
            this.ctx.resume();
        }
    }

    toggleMute() {
        this.isMuted = !this.isMuted;
        if (this.isMuted) {
            this.stopLobbyMusic();
            this.stopQuestionMusic();
        }
        return this.isMuted;
    }

    playOsc(type, freq, duration, startOffset = 0, startVol = 0.1, endVol = 0) {
        if (this.isMuted) return;
        this.init();
        
        const osc = this.ctx.createOscillator();
        const gain = this.ctx.createGain();
        
        osc.type = type;
        osc.frequency.setValueAtTime(freq, this.ctx.currentTime + startOffset);
        
        gain.gain.setValueAtTime(startVol, this.ctx.currentTime + startOffset);
        gain.gain.exponentialRampToValueAtTime(Math.max(endVol, 0.0001), this.ctx.currentTime + startOffset + duration);
        
        osc.connect(gain);
        gain.connect(this.ctx.destination);
        
        osc.start(this.ctx.currentTime + startOffset);
        osc.stop(this.ctx.currentTime + startOffset + duration);
    }

    // Play tick-tock sound for countdown
    playTick() {
        this.playOsc('sine', 600, 0.05, 0, 0.15, 0.001);
    }

    // Play final timeout sound
    playTimeUp() {
        this.playOsc('triangle', 180, 0.4, 0, 0.2, 0.001);
        setTimeout(() => {
            this.playOsc('triangle', 140, 0.5, 0, 0.2, 0.001);
        }, 150);
    }

    // Correct Answer Arpeggio
    playCorrect() {
        const notes = [261.63, 329.63, 392.00, 523.25]; // C4, E4, G4, C5
        notes.forEach((freq, idx) => {
            this.playOsc('sine', freq, 0.3, idx * 0.08, 0.12, 0.001);
        });
    }

    // Incorrect Answer Buzz
    playIncorrect() {
        this.init();
        if (this.isMuted) return;
        
        const osc = this.ctx.createOscillator();
        const gain = this.ctx.createGain();
        
        osc.type = 'sawtooth';
        osc.frequency.setValueAtTime(150, this.ctx.currentTime);
        osc.frequency.linearRampToValueAtTime(70, this.ctx.currentTime + 0.4);
        
        gain.gain.setValueAtTime(0.15, this.ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.0001, this.ctx.currentTime + 0.4);
        
        osc.connect(gain);
        gain.connect(this.ctx.destination);
        
        osc.start();
        osc.stop(this.ctx.currentTime + 0.4);
    }

    // Start Retro Synthesizer Lobby Loop
    startLobbyMusic() {
        if (this.isMuted) return;
        this.init();
        this.stopLobbyMusic();
        
        let beatIdx = 0;
        const secondsPerBeat = 60 / this.lobbyTempo;
        
        const playNextNote = () => {
            if (this.isMuted) return;
            const [note, duration] = this.lobbySequence[beatIdx];
            const freq = this.noteFreqs[note];
            
            if (freq) {
                // Play soft triangle bass/chords
                this.playOsc('triangle', freq, secondsPerBeat * duration * 0.9, 0, 0.07, 0.001);
                
                // Overlay a tiny pluck on every odd beat
                if (beatIdx % 2 === 0) {
                    this.playOsc('sine', freq * 2, 0.1, 0, 0.02, 0.001);
                }
            }
            
            beatIdx = (beatIdx + 1) % this.lobbySequence.length;
        };

        playNextNote();
        this.lobbyInterval = setInterval(playNextNote, secondsPerBeat * 1000);
    }

    stopLobbyMusic() {
        if (this.lobbyInterval) {
            clearInterval(this.lobbyInterval);
            this.lobbyInterval = null;
        }
    }

    // Start Driving Retro Synthesizer Question loop
    startQuestionMusic() {
        if (this.isMuted) return;
        this.init();
        this.stopQuestionMusic();
        
        let beatIdx = 0;
        const secondsPerBeat = 60 / this.questionTempo;
        
        const playNextBeat = () => {
            if (this.isMuted) return;
            const bassline = ['E3', 'E3', 'G3', 'A3', 'E3', 'E3', 'D3', 'D#3'];
            const note = bassline[beatIdx % bassline.length];
            const freq = this.noteFreqs[note] || 164.81;
            
            // Pulse driving low sawtooth bass note
            this.playOsc('sawtooth', freq, secondsPerBeat * 0.45, 0, 0.04, 0.001);
            
            // Add a high ticking rhythm
            if (beatIdx % 4 === 0) {
                // Tense high pulse
                this.playOsc('sine', freq * 3, 0.1, 0, 0.015, 0.001);
            } else {
                // Off-beat short tick
                this.playOsc('sine', 1000, 0.03, 0, 0.008, 0.001);
            }
            
            beatIdx++;
        };

        playNextBeat();
        this.questionInterval = setInterval(playNextBeat, secondsPerBeat * 1000);
    }

    stopQuestionMusic() {
        if (this.questionInterval) {
            clearInterval(this.questionInterval);
            this.questionInterval = null;
        }
    }

    // Play triumphant fanfare for podium
    playFanfare() {
        const root = 261.63; // C4
        const chord = [root, root * 1.25, root * 1.5, root * 2]; // C, E, G, C5
        
        chord.forEach((freq, i) => {
            this.playOsc('triangle', freq, 1.2, i * 0.1, 0.08, 0.001);
            this.playOsc('sine', freq * 2, 1.0, i * 0.1, 0.04, 0.001);
        });
        
        setTimeout(() => {
            chord.forEach((freq, i) => {
                this.playOsc('triangle', freq * 1.334, 1.8, i * 0.1, 0.1, 0.001); // F major transition
                this.playOsc('sine', freq * 2.668, 1.5, i * 0.1, 0.04, 0.001);
            });
        }, 800);
    }
}

// Global instance
const gameAudio = new MainkuizAudio();
