<?php

// Six-decimal boundaries match PostgreSQL geometry; adjacent thirds share an edge.
return [
    ['name_de' => 'Drittel A', 'name_en' => 'Third A', 'x' => 0, 'y' => 0, 'width' => .333333, 'height' => 1],
    ['name_de' => 'Drittel B', 'name_en' => 'Third B', 'x' => .333333, 'y' => 0, 'width' => .333334, 'height' => 1],
    ['name_de' => 'Drittel C', 'name_en' => 'Third C', 'x' => .666667, 'y' => 0, 'width' => .333333, 'height' => 1],
    ['name_de' => 'Viertel A', 'name_en' => 'Quarter A', 'x' => 0, 'y' => 0, 'width' => .5, 'height' => .5],
    ['name_de' => 'Viertel B', 'name_en' => 'Quarter B', 'x' => 0, 'y' => .5, 'width' => .5, 'height' => .5],
    ['name_de' => 'Viertel C', 'name_en' => 'Quarter C', 'x' => .5, 'y' => 0, 'width' => .5, 'height' => .5],
    ['name_de' => 'Viertel D', 'name_en' => 'Quarter D', 'x' => .5, 'y' => .5, 'width' => .5, 'height' => .5],
];
