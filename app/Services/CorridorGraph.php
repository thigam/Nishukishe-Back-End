<?php
// app/Services/CorridorGraph.php (DB version, no versioning)
namespace App\Services;

use Illuminate\Support\Facades\DB;

class CorridorGraph
{
    private bool $loaded=false;

    private int $l1Res=7, $l0Res=6;

    private array $stations=[];       // station_id => ['center'=>[lat,lng], 'l1_cell','l0_cell', 'members'=>[stop_id...]]
    private array $stopToStation=[];  // stop_id => station_id

    private array $l1Cells=[];        // cell_id => ['center'=>[lat,lng], 'neighbors'=>[...], 'l0_parent'=>...]
    private array $l0Cells=[];
    private array $l1Edges=[];        // edges[a][b] = [['from','to','minutes'], ...]
    private array $l0Edges=[];

    public function __construct() { $this->load(); }

    public function isLoaded(): bool { return $this->loaded; }
    public function l1Res(): int { return $this->l1Res; }
    public function l0Res(): int { return $this->l0Res; }

    public function stationsInL1Cell(string $c): array {
        $out=[]; foreach ($this->stations as $sid=>$m) if (($m['l1_cell']??null)===$c) $out[]=$sid; return $out;
    }
    public function l1Cells(): array { return array_keys($this->l1Cells); }
    public function l0Cells(): array { return array_keys($this->l0Cells); }
    public function l1Neighbors(string $c): array { return $this->l1Cells[$c]['neighbors'] ?? []; }
    public function l0Neighbors(string $c): array { return $this->l0Cells[$c]['neighbors'] ?? []; }
    public function l1Center(string $c): ?array { return $this->l1Cells[$c]['center'] ?? null; }
    public function l0Center(string $c): ?array { return $this->l0Cells[$c]['center'] ?? null; }
    public function l1EdgeConnections(string $a,string $b): array { return $this->l1Edges[$a][$b] ?? []; }
    public function l0EdgeConnections(string $a,string $b): array { return $this->l0Edges[$a][$b] ?? []; }
    public function l1CellsUnderL0Set(array $l0Set): array {
        $out=[]; foreach ($this->l1Cells as $id=>$m) if (isset($l0Set[$m['l0_parent'] ?? ''])) $out[]=$id; return $out;
    }
    public function memberStopsOfStations(array $stationIds): array {
        $stops=[]; foreach ($stationIds as $sid) foreach ($this->stations[$sid]['members'] ?? [] as $s) $stops[$s]=true;
        return array_keys($stops);
    }

    private function load(): void
    {
        // Stations + members
        $rows = DB::table('corr_stations')->get();
        foreach ($rows as $r) {
            $this->stations[$r->station_id] = [
                'center'=>[(float)$r->lat,(float)$r->lng],
                'l1_cell'=>$r->l1_cell, 'l0_cell'=>$r->l0_cell, 'members'=>[]
            ];
        }
        $m = DB::table('corr_station_members')->get();
        foreach ($m as $row) {
            $this->stations[$row->station_id]['members'][] = (string)$row->stop_id;
            $this->stopToStation[(string)$row->stop_id] = $row->station_id;
        }

        // Cells
        $c1 = DB::table('corr_cells')->where('level',1)->get();
        foreach ($c1 as $r) { $this->l1Cells[$r->cell_id] = ['center'=>[(float)$r->lat,(float)$r->lng],'neighbors'=>[],'l0_parent'=>$r->l0_parent]; }
        $c0 = DB::table('corr_cells')->where('level',0)->get();
        foreach ($c0 as $r) { $this->l0Cells[$r->cell_id] = ['center'=>[(float)$r->lat,(float)$r->lng],'neighbors'=>[]]; }

        // Neighbors
        $n1 = DB::table('corr_cell_neighbors')->where('level',1)->get();
        foreach ($n1 as $e) { $this->l1Cells[$e->cell_a]['neighbors'][] = $e->cell_b; }
        $n0 = DB::table('corr_cell_neighbors')->where('level',0)->get();
        foreach ($n0 as $e) { $this->l0Cells[$e->cell_a]['neighbors'][] = $e->cell_b; }

        // Edge summaries (top-M)
        $this->l1Edges=[]; $edges1 = DB::table('corr_cell_edge_summaries')
            ->where('level',1)->orderBy('rank')->get();
        foreach ($edges1 as $e) {
            $this->l1Edges[$e->from_cell][$e->to_cell][] = [
                'from'=>$e->from_station,'to'=>$e->to_station,'minutes'=>(float)$e->minutes
            ];
        }

        $this->l0Edges=[]; $edges0 = DB::table('corr_cell_edge_summaries')
            ->where('level',0)->orderBy('rank')->get();
        foreach ($edges0 as $e) {
            $this->l0Edges[$e->from_cell][$e->to_cell][] = [
                'from'=>$e->from_station,'to'=>$e->to_station,'minutes'=>(float)$e->minutes
            ];
        }

        $this->loaded = !empty($this->stations) && !empty($this->l1Cells) && !empty($this->l0Cells);
    }
}

