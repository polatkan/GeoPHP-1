<?php

/*
 * This file is part of the GeoPHP package.
 * Copyright (c) 2011 - 2016 Patrick Hayes and contributors
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GeoPHP\Adapter;

use GeoPHP\Exception;
use GeoPHP\Geo;
use GeoPHP\Geometry\Geometry;
use GeoPHP\Geometry\GeometryCollection;
use GeoPHP\Geometry\LineString;
use GeoPHP\Geometry\Point;
use GeoPHP\Geometry\Polygon;

/**
 * GeoRSS encoder/decoder.
 */
class GeoRSS extends Adapter
{
    private $xmlobj;
    private $namespace = false;
    private $nss = ''; // Name-space string. eg 'georss:'

    /**
     * Read GeoRSS string into geometry objects.
     *
     * @param string $georss - an XML feed containing geoRSS
     *
     * @return Geometry|GeometryCollection
     */
    public function read($gpx)
    {
        return $this->geomFromText($gpx);
    }

    /**
     * Serialize geometries into a GeoRSS string.
     *
     * @param Geometry $geometry
     *
     * @return string The georss string representation of the input geometries
     */
    public function write(Geometry $geometry, $namespace = false)
    {
        if ($namespace) {
            $this->namespace = $namespace;
            $this->nss = $namespace . ':';
        }

        return $this->geometryToGeoRSS($geometry);
    }

    public function geomFromText($text)
    {
        // Change to lower-case, strip all CDATA, and de-namespace
        $text = strtolower($text);
        $text = preg_replace('/<!\[cdata\[(.*?)\]\]>/s', '', $text);

        // Load into DOMDOcument
        $xmlobj = new \DOMDocument();
        @$xmlobj->loadXML($text);
        if ($xmlobj === false) {
            throw new Exception('Invalid GeoRSS: ' . $text);
        }

        $this->xmlobj = $xmlobj;
        try {
            $geom = $this->geomFromXML();
        } catch (\InvalidText $e) {
            throw new Exception('Cannot Read Geometry From GeoRSS: ' . $text);
        } catch (\Exception $e) {
            throw $e;
        }

        return $geom;
    }

    protected function geomFromXML()
    {
        $geometries = array();
        $geometries = array_merge($geometries, $this->parsePoints());
        $geometries = array_merge($geometries, $this->parseLines());
        $geometries = array_merge($geometries, $this->parsePolygons());
        $geometries = array_merge($geometries, $this->parseBoxes());
        $geometries = array_merge($geometries, $this->parseCircles());

        if (empty($geometries)) {
            throw new Exception('Invalid / Empty GeoRSS');
        }

        return Geo::geometryReduce($geometries);
    }

    protected function getPointsFromCoords($string)
    {
        $coords = array();

        if (empty($string)) {
            return $coords;
        }

        $latlon = explode(' ', $string);
        foreach ($latlon as $key => $item) {
            if (!($key % 2)) {
                // It's a latitude
                $lat = $item;
            } else {
                // It's a longitude
                $lon = $item;
                $coords[] = new Point($lon, $lat);
            }
        }

        return $coords;
    }

    protected function parsePoints()
    {
        $points = array();
        $ptElements = $this->xmlobj->getElementsByTagName('point');
        foreach ($ptElements as $pt) {
            if ($pt->hasChildNodes()) {
                $pointArray = $this->getPointsFromCoords(trim($pt->firstChild->nodeValue));
            }
            if (!empty($pointArray)) {
                $points[] = $pointArray[0];
            } else {
                $points[] = new Point();
            }
        }

        return $points;
    }

    protected function parseLines()
    {
        $lines = array();
        $lineElements = $this->xmlobj->getElementsByTagName('line');
        foreach ($lineElements as $line) {
            $components = $this->getPointsFromCoords(trim($line->firstChild->nodeValue));
            $lines[] = new LineString($components);
        }

        return $lines;
    }

    protected function parsePolygons()
    {
        $polygons = array();
        $polyElements = $this->xmlobj->getElementsByTagName('polygon');
        foreach ($polyElements as $poly) {
            if ($poly->hasChildNodes()) {
                $points = $this->getPointsFromCoords(trim($poly->firstChild->nodeValue));
                $exteriorRing = new LineString($points);
                $polygons[] = new Polygon(array($exteriorRing));
            } else {
                // It's an EMPTY polygon
                $polygons[] = new Polygon();
            }
        }

        return $polygons;
    }

    // Boxes are rendered into polygons
    protected function parseBoxes()
    {
        $polygons = array();
        $boxElements = $this->xmlobj->getElementsByTagName('box');
        foreach ($boxElements as $box) {
            $parts = explode(' ', trim($box->firstChild->nodeValue));
            $components = array(
                new Point($parts[3], $parts[2]),
                new Point($parts[3], $parts[0]),
                new Point($parts[1], $parts[0]),
                new Point($parts[1], $parts[2]),
                new Point($parts[3], $parts[2]),
            );
            $exteriorRing = new LineString($components);
            $polygons[] = new Polygon(array($exteriorRing));
        }

        return $polygons;
    }

    // Circles are rendered into points
    // @@TODO: Add good support once we have circular-string geometry support
    protected function parseCircles()
    {
        $points = array();
        $circleElements = $this->xmlobj->getElementsByTagName('circle');
        foreach ($circleElements as $circle) {
            $parts = explode(' ', trim($circle->firstChild->nodeValue));
            $points[] = new Point($parts[1], $parts[0]);
        }

        return $points;
    }

    protected function geometryToGeoRSS($geom)
    {
        $type = strtolower($geom->getGeomType());

        switch ($type) {
            case 'point':
                return $this->pointToGeoRSS($geom);
            case 'linestring':
                return $this->linestringToGeoRSS($geom);
            case 'polygon':
                return $this->PolygonToGeoRSS($geom);
            case 'multipoint':
            case 'multilinestring':
            case 'multipolygon':
            case 'geometrycollection':
                return $this->collectionToGeoRSS($geom);
        }
    }

    private function pointToGeoRSS($geom)
    {
        $out = '<' . $this->nss . 'point>';
        if (!$geom->isEmpty()) {
            $out .= $geom->getY() . ' ' . $geom->getX();
        }
        $out .= '</' . $this->nss . 'point>';

        return $out;
    }

    private function linestringToGeoRSS($geom)
    {
        $output = '<' . $this->nss . 'line>';
        foreach ($geom->getComponents() as $k => $point) {
            $output .= $point->getY() . ' ' . $point->getX();
            if ($k < ($geom->numGeometries() - 1)) {
                $output .= ' ';
            }
        }
        $output .= '</' . $this->nss . 'line>';

        return $output;
    }

    private function polygonToGeoRSS($geom)
    {
        $output = '<' . $this->nss . 'polygon>';
        $exteriorRing = $geom->exteriorRing();
        foreach ($exteriorRing->getComponents() as $k => $point) {
            $output .= $point->getY() . ' ' . $point->getX();
            if ($k < ($exteriorRing->numGeometries() - 1)) {
                $output .= ' ';
            }
        }
        $output .= '</' . $this->nss . 'polygon>';

        return $output;
    }

    public function collectionToGeoRSS($geom)
    {
        $georss = '<' . $this->nss . 'where>';
        foreach ($geom->getComponents() as $comp) {
            $georss .= $this->geometryToGeoRSS($comp);
        }

        $georss .= '</' . $this->nss . 'where>';

        return $georss;
    }
}
