# Host load during the runs

A run is flagged noisy when the host 1-minute load average during it exceeded connections + 2 + 6 (the study cannot generate more runnable threads than that on its own), or when it has no samples. Host: 8 vCPUs. 31 of 220 runs flagged. (The 1-minute average lags, so a flag can also trail a short burst.)

| run | load1 mean | load1 max | allowed |
|---|---:|---:|---:|
| baseline-none_c02_r01_none | 17.4 | 17.9 | 10 |
| baseline-none_c02_r02_none | 17 | 17.9 | 10 |
| baseline-none_c02_r03_none | 16.1 | 16.5 | 10 |
| baseline-none_c02_r04_none | 15.3 | 15.3 | 10 |
| baseline-none_c02_r05_none | 14.9 | 15.3 | 10 |
| sequential-free_c04_r01_hybrid | 13.2 | 13.7 | 12 |
| sequential-free_c04_r01_immediate | 13.5 | 13.7 | 12 |
| sequential-free_c04_r02_hybrid | 12.9 | 13 | 12 |
| sequential-free_c04_r02_immediate | 12.6 | 13 | 12 |
| sequential-free_c04_r03_immediate | 12.3 | 13 | 12 |
| sequential-wave_c02_r01_hybrid | 26.9 | 27.8 | 10 |
| sequential-wave_c02_r01_immediate | 28.8 | 29.8 | 10 |
| sequential-wave_c02_r02_hybrid | 25 | 25.9 | 10 |
| sequential-wave_c02_r02_immediate | 23.4 | 24.1 | 10 |
| sequential-wave_c02_r03_hybrid | 21.7 | 22.7 | 10 |
| sequential-wave_c02_r03_immediate | 22.3 | 22.7 | 10 |
| sequential-wave_c02_r04_hybrid | 20.2 | 21.2 | 10 |
| sequential-wave_c02_r04_immediate | 19.2 | 19.7 | 10 |
| sequential-wave_c02_r05_hybrid | 17 | 17 | 10 |
| sequential-wave_c02_r05_immediate | 17.9 | 18.3 | 10 |
| sequential-wave_c02_r06_hybrid | 16.4 | 17 | 10 |
| sequential-wave_c02_r06_immediate | 15.5 | 16.1 | 10 |
| sequential-wave_c02_r07_hybrid | 14.3 | 15 | 10 |
| sequential-wave_c02_r07_immediate | 15 | 15 | 10 |
| sequential-wave_c02_r08_hybrid | 13.5 | 13.9 | 10 |
| sequential-wave_c02_r08_immediate | 12.9 | 13.1 | 10 |
| sequential-wave_c02_r09_hybrid | 11.7 | 12.3 | 10 |
| sequential-wave_c02_r09_immediate | 12.3 | 13.1 | 10 |
| sequential-wave_c02_r10_hybrid | 11.4 | 11.5 | 10 |
| sequential-wave_c02_r10_immediate | 11.2 | 11.4 | 10 |
| shuffled-wave_c02_r01_immediate | 10.1 | 10.6 | 10 |
